<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Federation;

use DateTime;
use DateTimeZone;
use OCA\Social\Cron\Queue;
use OCA\Social\Db\HostBreakerRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\HostBreaker;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tests\Integration\Helper\FaultServer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The delivery cron against peers that misbehave: hang, fail, ask to be left
 * alone, cut their answer off or redirect for ever.
 *
 * The peers are fake inboxes on this machine (`FaultServer`), each a host
 * name of its own. What runs is the real `Cron\Queue` pass over real
 * `social_req_queue` rows, through the real `ActivityService` and the real
 * `CurlService` and HTTP client. Three things are put in for the test, and
 * nothing else:
 *
 *  - a `ConfigService` that lets this one object graph reach the loopback,
 *    which every production path refuses unless the administrator allowed
 *    local servers — the instance's own setting is not touched;
 *  - no signature: the signer is not what is being tested;
 *  - a queue that hands the pass only this test's rows, because the
 *    integration database is shared and another test's leftovers, addressed
 *    to hosts that do not exist, must not decide how long a pass takes.
 *
 * Every pass runs with a two-second timeout (`withRequestTimeout()`, as a
 * bounded production call does) in place of the cron's thirty. Time is
 * moved on by moving the stored timestamps back — `last` on the rows,
 * `open_until` and `last_failure` on the breaker — because both the
 * schedule and the breaker read the wall clock.
 */
class DeliveryUnderFaultsTest extends TestCase {
	private const AUTHOR = 'https://cloud.example.org/faults/users/author';
	private const TIMEOUT = 2;

	private static ?FaultServer $server = null;

	/** what keeps this test's peers apart from every other test's */
	private string $name = '';
	/** @var array<string, true> the hosts this test addressed, for the breaker's cleanup */
	private array $hosts = [];
	private ConfigService $config;
	private Queue $job;

	public static function setUpBeforeClass(): void {
		self::$server = FaultServer::start();
	}

	public static function tearDownAfterClass(): void {
		self::$server?->stop();
		self::$server = null;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->name = bin2hex(random_bytes(4));
		Server::get(RequestQueueRequest::class)->deleteByAuthor(self::AUTHOR);
		$this->buildJob();
	}

	protected function tearDown(): void {
		Server::get(RequestQueueRequest::class)->deleteByAuthor(self::AUTHOR);
		foreach (array_keys($this->hosts) as $host) {
			Server::get(HostBreakerRequest::class)->close($host);
		}
		parent::tearDown();
	}

	/**
	 * One peer that hangs, and several that answer: the ones that answer are
	 * delivered in the same wave, before the hanging one is given up on, and
	 * the hanging one costs one timeout however many rows it has — the
	 * breaker holds the rest back unattempted.
	 */
	public function testOneHangingPeerDoesNotHoldUpTheOthers(): void {
		for ($i = 1; $i <= 3; $i++) {
			$this->queue('slow', $this->name, 'slow-' . $i);
		}
		for ($i = 1; $i <= 6; $i++) {
			$this->queue('ok', $this->name . $i, 'ok-' . $i);
		}

		$started = microtime(true);
		$this->pass();
		$took = microtime(true) - $started;

		for ($i = 1; $i <= 6; $i++) {
			$hits = self::$server->hitsOn('ok', $this->name . $i);
			$this->assertCount(1, $hits, 'a peer that answers was not delivered to exactly once');
			$this->assertLessThan(self::TIMEOUT, $hits[0]['at'] - $started, 'a peer that answers waited for the one that hangs');
			$this->assertSame(RequestQueue::STATUS_SUCCESS, $this->row('ok-' . $i)['status']);
		}

		$this->assertCount(1, self::$server->hitsOn('slow', $this->name), 'the hanging peer was asked more than once in one pass');
		$this->assertLessThan(2 * self::TIMEOUT, $took, 'the hanging peer cost more than one timeout');

		$slow = [$this->row('slow-1'), $this->row('slow-2'), $this->row('slow-3')];
		$this->assertSame([RequestQueue::STATUS_STANDBY, RequestQueue::STATUS_STANDBY, RequestQueue::STATUS_STANDBY], array_column($slow, 'status'), 'a row to the hanging peer was dropped');
		$this->assertSame(1, array_sum(array_column($slow, 'tries')), 'more than one row to the hanging peer was spent');
		$held = array_filter($slow, static fn (array $row): bool => $row['tries'] === 0);
		$this->assertCount(2, $held);
		foreach ($held as $row) {
			$this->assertGreaterThan(time(), $row['last'], 'a row the breaker held back is due before the breaker closes');
		}
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: int}> how a peer fails,
	 *                                                         how many requests one delivery makes of it, and how
	 *                                                         long the peer is then left alone
	 */
	public static function failuresThatMayPass(): array {
		return [
			'a 500' => ['error', 1, HostBreaker::BASE],
			'a 429 with Retry-After: 120' => ['busy', 1, 120],
			'a 503 with Retry-After: 120' => ['unavailable', 1, 120],
			'a 503 with Retry-After five minutes from now, as a date' => ['dated', 1, 300],
			'a 429 with Retry-After a week, held for the hour at most' => ['week', 1, HostBreaker::MAX],
			'a timeout' => ['slow', 1, HostBreaker::BASE],
			// the first answer, then each hop the single-request path follows
			'a redirect loop' => ['loop', 7, HostBreaker::BASE],
		];
	}

	/**
	 * A failure that may pass keeps the row, spends one of its tries, and
	 * leaves the peer alone for the breaker's first interval — or for as long
	 * as the peer's `Retry-After` asked, up to the breaker's ceiling.
	 */
	#[DataProvider('failuresThatMayPass')]
	public function testAFailureThatMayPassIsRetriedAndThePeerLeftAlone(string $behaviour, int $requests, int $held): void {
		$this->queue($behaviour, $this->name, 'only');

		$this->pass();

		$this->assertCount($requests, self::$server->hitsOn($behaviour, $this->name));
		$row = $this->row('only');
		$this->assertSame(RequestQueue::STATUS_STANDBY, $row['status'], 'the delivery was dropped');
		$this->assertSame(1, $row['tries']);
		$breaker = $this->breaker($behaviour);
		$this->assertNotNull($breaker, 'the failing peer is not left alone');
		$this->assertSame(1, $breaker['strikes']);
		// a date is read against the clock, which may tick between the two
		$this->assertEqualsWithDelta($held, $breaker['open_until'] - $breaker['last_failure'], ($behaviour === 'dated') ? 2 : 0);
	}

	/**
	 * A peer that never answers 4xx-for-good is retried on the schedule
	 * `RequestQueueService` documents — 16 s after the first failure, 31 s
	 * after the second, 96 s after the third — and not a moment earlier,
	 * while the breaker doubles: 60 s, 120 s, 240 s. When the peer is back
	 * the row is delivered and the breaker forgets the peer.
	 */
	public function testRetriesFollowTheDocumentedScheduleUntilThePeerIsBack(): void {
		$this->queue('flaky', $this->name, 'only');

		foreach ([1 => 16, 2 => 31, 3 => 96] as $tries => $wait) {
			$this->pass();
			$this->assertCount($tries, self::$server->hitsOn('flaky', $this->name), 'attempt ' . $tries . ' was not made');
			$this->assertSame($tries, $this->row('only')['tries']);
			$breaker = $this->breaker('flaky');
			$this->assertSame(HostBreaker::BASE * 2 ** ($tries - 1), $breaker['open_until'] - $breaker['last_failure'], 'the breaker did not double');
			$this->assertSame($wait, RequestQueueService::retryDelay($tries));

			// the breaker's interval over, the schedule's not yet
			$this->elapseBreaker('flaky', $breaker['open_until'] - $breaker['last_failure'] + 1);
			$this->elapse($wait - 2);
			$this->pass();
			$this->assertCount($tries, self::$server->hitsOn('flaky', $this->name), 'a retry was made before its time');

			$this->elapse(3);
		}

		self::$server->bringUp('flaky', $this->name);
		$this->pass();

		$this->assertCount(4, self::$server->hitsOn('flaky', $this->name));
		$this->assertSame(RequestQueue::STATUS_SUCCESS, $this->row('only')['status']);
		$this->assertNull($this->breaker('flaky'), 'the breaker still holds a peer that answered');
	}

	/**
	 * Every row reaches its peer exactly once, whatever the peers did on the
	 * way: those that failed are retried until they are back, and nothing a
	 * peer acknowledged is ever sent to it again. (A peer that hangs past the
	 * timeout may still take in what it was sent, and is sent it again: that
	 * is `testAnAnswerCutOffIsSentAgain`'s case.)
	 */
	public function testNothingIsLostOrDeliveredTwice(): void {
		$expected = [];
		foreach (['ok', 'flaky', 'error', 'busy', 'unavailable'] as $behaviour) {
			for ($i = 1; $i <= 2; $i++) {
				$this->queue($behaviour, $this->name, $behaviour . '-' . $i);
				$expected[$this->host($behaviour) . ' ' . $this->activityId($behaviour . '-' . $i)] = true;
			}
		}

		for ($round = 1; $round <= 4 && $this->waiting() > 0; $round++) {
			$this->pass();
			if ($round === 1) {
				foreach (['flaky', 'error', 'busy', 'unavailable'] as $behaviour) {
					self::$server->bringUp($behaviour, $this->name);
				}
			}
			foreach (['flaky', 'error', 'busy', 'unavailable'] as $behaviour) {
				$this->elapseBreaker($behaviour, HostBreaker::MAX);
			}
			$this->elapse(HostBreaker::MAX);
		}

		$this->assertSame(0, $this->waiting(), 'a delivery never got through');
		$accepted = [];
		foreach (self::$server->hits() as $hit) {
			if (str_ends_with($hit['host'], '-' . $this->name . '.faults.localhost') && $hit['answer'] >= 200 && $hit['answer'] < 300) {
				$key = $hit['host'] . ' ' . $hit['id'];
				$accepted[$key] = ($accepted[$key] ?? 0) + 1;
			}
		}
		ksort($accepted);
		ksort($expected);
		$this->assertSame(array_fill_keys(array_keys($expected), 1), $accepted, 'a delivery was lost or acknowledged twice');
	}

	/**
	 * A peer that takes the delivery in and then hangs up before its answer
	 * is complete has, as far as this side can tell, not taken it: the row is
	 * kept and sent again. Delivery is at least once; the peer recognises the
	 * activity by its id.
	 */
	public function testAnAnswerCutOffIsSentAgain(): void {
		$this->queue('reset', $this->name, 'only');

		$this->pass();
		$this->assertCount(1, self::$server->hitsOn('reset', $this->name));
		$this->assertSame(RequestQueue::STATUS_STANDBY, $this->row('only')['status'], 'a delivery whose answer was cut off counted as delivered');
		$this->assertSame(1, $this->row('only')['tries']);

		self::$server->bringUp('reset', $this->name);
		$this->elapseBreaker('reset', HostBreaker::MAX);
		$this->elapse(HostBreaker::MAX);
		$this->pass();

		$this->assertSame(RequestQueue::STATUS_SUCCESS, $this->row('only')['status']);
		$this->assertSame(
			[$this->activityId('only'), $this->activityId('only')],
			array_column(self::$server->hitsOn('reset', $this->name), 'id')
		);
	}

	// --- the harness ------------------------------------------------------

	/**
	 * The cron job as the container builds it, but for the three stand-ins
	 * the class docblock names.
	 */
	private function buildJob(): void {
		$this->config = new class(null, Server::get(IAppConfig::class), Server::get(IUserConfig::class), Server::get(IConfig::class), Server::get(IURLGenerator::class)) extends ConfigService {
			#[\Override]
			public function isLocalNetworkAllowed(): bool {
				return true;
			}
		};

		$curl = $this->construct(CurlService::class, [ConfigService::class => $this->config]);
		$signatures = $this->createStub(SignatureService::class);
		$signatures->method('signRequest')->willReturn([]);
		$activities = $this->construct(ActivityService::class, [
			CurlService::class => $curl,
			SignatureService::class => $signatures,
			ConfigService::class => $this->config,
		]);
		$queue = new class(Server::get(RequestQueueRequest::class)) extends RequestQueueService {
			#[\Override]
			public function getRequestStandby(int &$total = 0): array {
				$mine = array_values(array_filter(
					parent::getRequestStandby($total),
					static fn (RequestQueue $request): bool => $request->getAuthor() === DeliveryUnderFaultsTest::author()
				));
				$total = count($mine);

				return $mine;
			}
		};

		$this->job = new Queue(
			Server::get(ITimeFactory::class),
			$queue,
			Server::get(StreamQueueService::class),
			$activities,
			Server::get(LoggerInterface::class),
			Server::get(CacheActorService::class),
		);
	}

	/** @internal for the queue stand-in */
	public static function author(): string {
		return self::AUTHOR;
	}

	/**
	 * A class built from the container's services, with some of them replaced.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @param array<class-string, object> $replace
	 * @return T
	 */
	private function construct(string $class, array $replace): object {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			$arguments[] = $replace[$type->getName()] ?? Server::get($type->getName());
		}

		return new $class(...$arguments);
	}

	/** One pass of the delivery cron, bounded to TIMEOUT a request. */
	private function pass(): void {
		$this->config->withRequestTimeout(
			self::TIMEOUT,
			fn () => (new ReflectionMethod(Queue::class, 'run'))->invoke($this->job, null)
		);
	}

	private function host(string $behaviour): string {
		return self::$server->host($behaviour, $this->name);
	}

	private function activityId(string $key): string {
		return self::AUTHOR . '/activity/' . $this->name . '/' . $key;
	}

	/** Queues one delivery of an activity of its own to a peer. */
	private function queue(string $behaviour, string $name, string $key): void {
		$this->hosts[self::$server->host($behaviour, $name)] = true;
		$request = new RequestQueue(
			(string)json_encode(['type' => 'Create', 'id' => $this->activityId($key)], JSON_UNESCAPED_SLASHES),
			new InstancePath(self::$server->inbox($behaviour, $name), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM),
			self::AUTHOR
		);
		$request->setToken('faults-' . $this->name . '-' . $key);
		Server::get(RequestQueueRequest::class)->create($request);
	}

	/**
	 * One of this test's rows as the database holds it.
	 *
	 * @return array{status: int, tries: int, last: int}
	 */
	private function row(string $key): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('status', 'tries', 'last')
			->from('social_req_queue')
			->where($qb->expr()->eq('token', $qb->createNamedParameter('faults-' . $this->name . '-' . $key)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();
		$this->assertIsArray($row, 'the row ' . $key . ' is gone');

		$last = ($row['last'] === null) ? 0 : (new DateTime((string)$row['last'], new DateTimeZone('UTC')))->getTimestamp();

		return ['status' => (int)$row['status'], 'tries' => (int)$row['tries'], 'last' => $last];
	}

	/** This test's rows not delivered yet. */
	private function waiting(): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from('social_req_queue')
			->where($qb->expr()->eq('author_prim', $qb->createNamedParameter(md5(self::AUTHOR))))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter(RequestQueue::STATUS_SUCCESS, IQueryBuilder::PARAM_INT)));
		$cursor = $qb->executeQuery();
		$waiting = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $waiting;
	}

	/**
	 * The breaker's record of a peer, or null when it holds nothing against it.
	 *
	 * @return array{strikes: int, open_until: int, last_failure: int}|null
	 */
	private function breaker(string $behaviour): ?array {
		return Server::get(HostBreakerRequest::class)->failingSince(0)[$this->host($behaviour)] ?? null;
	}

	/** Moves this test's rows `$seconds` into the past, as far as the schedule can tell. */
	private function elapse(int $seconds): void {
		$connection = Server::get(IDBConnection::class);
		$select = $connection->getQueryBuilder();
		$select->select('id', 'last')
			->from('social_req_queue')
			->where($select->expr()->eq('author_prim', $select->createNamedParameter(md5(self::AUTHOR))))
			->andWhere($select->expr()->isNotNull('last'));
		$cursor = $select->executeQuery();
		$rows = $cursor->fetchAll();
		$cursor->closeCursor();

		foreach ($rows as $row) {
			$last = new DateTime((string)$row['last'], new DateTimeZone('UTC'));
			$update = $connection->getQueryBuilder();
			$update->update('social_req_queue')
				->set('last', $update->createNamedParameter(new DateTime('@' . ($last->getTimestamp() - $seconds)), IQueryBuilder::PARAM_DATE))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
		}
	}

	/** Moves the breaker's record of a peer `$seconds` into the past. */
	private function elapseBreaker(string $behaviour, int $seconds): void {
		$state = $this->breaker($behaviour);
		if ($state === null) {
			return;
		}

		Server::get(HostBreakerRequest::class)->open(
			$this->host($behaviour), $state['strikes'], $state['open_until'] - $seconds, $state['last_failure'] - $seconds
		);
	}
}
