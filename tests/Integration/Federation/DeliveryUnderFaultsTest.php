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
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tests\Integration\Helper\FaultServer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The delivery cron against peers that misbehave.
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
 * bounded production call does) in place of the cron's thirty.
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

}
