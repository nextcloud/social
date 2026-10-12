<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Integration\QueryCounter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The plan the database chooses for each query a page load or a cron pass
 * depends on, asked of the database itself.
 *
 * The statements are not rebuilt here: each is recorded while the production
 * method runs (`QueryCounter`) and handed back to `EXPLAIN` verbatim, with the
 * parameters it was bound with. A predicate added in a Request class, a
 * join that changes the driving table, an `OR` that defeats an index — what
 * turned the home timeline into a full read of `social_stream` in September —
 * all show up as a different plan.
 *
 * What is asserted, per database:
 *
 * - **SQLite** (`EXPLAIN QUERY PLAN`): every guarded table is reached by a
 *   `SEARCH … USING [COVERING] INDEX`, never a `SCAN`. The planner has no
 *   statistics on an instance nobody ran `ANALYZE` on, which is every one,
 *   so the plan does not depend on what the tables hold.
 * - **MySQL/MariaDB** (`EXPLAIN`): every guarded table has a key and an access
 *   type other than `ALL` or `index` (a full read of the table or of an
 *   index), and no step says `Using filesort` or `Using temporary` unless the
 *   query is listed as one that sorts, with the reason.
 * - **PostgreSQL** (`EXPLAIN` with `enable_seqscan` off): no `Seq Scan` on a
 *   guarded table. A couple of thousand posts fit in a few hundred pages, and
 *   at that size a sequential read is the cheaper plan; with it priced out, a
 *   `Seq Scan` that remains is one no index could replace.
 *
 * MySQL and PostgreSQL choose by statistics, so on those the tables are
 * filled to a couple of thousand posts first and `ANALYZE`d; on SQLite the
 * seed stays small.
 */
class QueryPlanTest extends TestCase {
	private const BASE = 'https://cloud.example.org/queryplan';
	private const REMOTE = 'https://remote.example/queryplan';
	private const FOLLOWED = 3;
	private const TAG = 'qptag';

	private static string $platform = '';
	private static ?Person $viewer = null;

	/** @var string[] cached actor ids */
	private static array $actors = [];

	/** @var string[] stream ids */
	private static array $streams = [];

	private string $fastPathBefore = '';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$connection = Server::get(IDBConnection::class);
		self::$platform = match ($connection->getDatabaseProvider()) {
			IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB => 'mysql',
			IDBConnection::PLATFORM_POSTGRES => 'pgsql',
			IDBConnection::PLATFORM_SQLITE => 'sqlite',
			default => '',
		};
		if (self::$platform === '') {
			return;
		}

		// the plans of MySQL and PostgreSQL follow the statistics, and on a
		// near-empty table a full read is the right plan
		$volume = self::$platform !== 'sqlite';
		$connection->beginTransaction();
		try {
			self::seed($volume ? 40 : 4, $volume ? 50 : 3, $volume ? 200 : 3, $volume ? 600 : 6);
			$connection->commit();
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}

		if ($volume) {
			foreach ([
				CoreRequestBuilder::TABLE_STREAM, CoreRequestBuilder::TABLE_STREAM_DEST,
				CoreRequestBuilder::TABLE_STREAM_TAGS, CoreRequestBuilder::TABLE_FOLLOWS,
				CoreRequestBuilder::TABLE_CACHE_ACTORS, CoreRequestBuilder::TABLE_REQUEST_QUEUE,
			] as $table) {
				$name = '*PREFIX*' . $table;
				// MySQL answers ANALYZE TABLE with a result set, which has to be read
				$connection->executeQuery(self::$platform === 'mysql' ? 'ANALYZE TABLE ' . $name : 'ANALYZE ' . $name)
					->fetchAll();
			}
		}
	}

	public static function tearDownAfterClass(): void {
		$connection = Server::get(IDBConnection::class);
		foreach (array_chunk(array_map('md5', self::$streams), 500) as $prims) {
			foreach ([
				[CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id'], [CoreRequestBuilder::TABLE_STREAM_TAGS, 'stream_id'],
				[CoreRequestBuilder::TABLE_STREAM, 'id_prim'],
			] as [$table, $column]) {
				$qb = $connection->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)));
				$qb->executeStatement();
			}
		}
		$qb = $connection->getQueryBuilder();
		$qb->delete(CoreRequestBuilder::TABLE_HASHTAGS)->where($qb->expr()->eq('hashtag', $qb->createNamedParameter(self::TAG)));
		$qb->executeStatement();
		$follows = Server::get(FollowsRequest::class);
		$cache = Server::get(CacheActorsRequest::class);
		foreach (self::$actors as $id) {
			$follows->deleteRelatedId($id);
			$cache->deleteCacheById($id);
		}
		Server::get(RequestQueueRequest::class)->deleteByAuthor(self::BASE . '/users/viewer');
		self::$streams = self::$actors = [];
		self::$viewer = null;

		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		if (self::$platform === '') {
			$this->markTestSkipped('query plans are asserted on SQLite, MySQL and PostgreSQL only');
		}
		$this->fastPathBefore = Server::get(ConfigService::class)->getAppValue(ConfigService::SOCIAL_DEST_NID_FILLED);
	}

	protected function tearDown(): void {
		if (self::$platform !== '') {
			$this->useHomeFastPath($this->fastPathBefore === '1');
		}
		parent::tearDown();
	}

	/**
	 * A local viewer following three of `$authors` remote accounts, each with
	 * `$posts` public posts of which one in ten carries a picture, one in
	 * twenty-five a video and one in four the hashtag `TAG`; `$followers`
	 * remote followers of the viewer; and
	 * `$queued` deliveries, most of them long given up on, as a queue that has
	 * been running for a while holds them.
	 */
	private static function seed(int $authors, int $posts, int $followers, int $queued): void {
		self::$viewer = self::person(self::BASE . '/users/viewer', 'qpviewer', true);
		Server::get(FollowsRequest::class)->generateLoopbackAccount(self::$viewer);

		$streamRequest = Server::get(StreamRequest::class);
		$published = time() - $authors * $posts;
		for ($a = 0; $a < $authors; $a++) {
			$author = self::person(self::REMOTE . '/users/author' . $a, 'qpauthor' . $a, false);
			if ($a < self::FOLLOWED) {
				self::follow(self::$viewer, $author);
			}
			for ($p = 0; $p < $posts; $p++) {
				$published++;
				$note = new Note();
				$note->setId(self::REMOTE . '/notes/' . $a . '-' . $p);
				$note->setAttributedTo($author->getId());
				$note->setTo(ACore::CONTEXT_PUBLIC);
				$note->setCcArray([$author->getFollowers()]);
				$note->setContent('<p>post ' . $p . '</p>');
				$note->setPublishedTime($published);
				$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $published));
				$kind = $p % 25 === 0 ? 'video' : ($p % 10 === 0 ? 'image' : '');
				if ($kind !== '') {
					$media = new MediaAttachment();
					$media->setId($a . '-' . $p);
					$media->setType($kind);
					$media->setUrl(self::REMOTE . '/media/' . $a . '-' . $p);
					$note->setAttachments([$media]);
				}
				if ($p % 4 === 0) {
					$note->setHashtags([self::TAG]);
				}
				$streamRequest->save($note);
				self::$streams[] = $note->getId();
			}
		}

		for ($f = 0; $f < $followers; $f++) {
			self::follow(self::person(self::REMOTE . '/users/follower' . $f, 'qpfollower' . $f, false), self::$viewer);
		}

		$queue = Server::get(RequestQueueRequest::class);
		for ($q = 0; $q < $queued; $q++) {
			$request = new RequestQueue(
				'{}', new InstancePath(self::REMOTE . '/inbox' . ($q % 50), InstancePath::TYPE_INBOX), self::BASE . '/users/viewer'
			);
			$request->setToken('qp' . $q);
			$request->setStatus($q % 12 === 0 ? RequestQueue::STATUS_STANDBY : RequestQueue::STATUS_ABANDONED);
			$queue->create($request);
		}
	}

	private static function person(string $id, string $name, bool $local): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername($name);
		$person->setAccount($name . ($local ? '@cloud.example.org' : '@remote.example'))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setSharedInbox($local ? '' : 'https://remote.example/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);
		$cache = Server::get(CacheActorsRequest::class);
		$cache->save($person);
		self::$actors[] = $id;

		return $cache->getFromId($id);
	}

	private static function follow(Person $actor, Person $object): void {
		$follow = new Follow();
		$follow->setId(self::REMOTE . '/follows/' . md5($actor->getId() . $object->getId()));
		$follow->setActorId($actor->getId());
		$follow->setObjectId($object->getId());
		$follow->setFollowId($object->getFollowers());
		$follow->setAccepted(true);
		Server::get(FollowsRequest::class)->save($follow);
	}

	private function useHomeFastPath(bool $fast): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_DEST_NID_FILLED, $fast ? '1' : '0');
		(new ReflectionProperty(StreamRequest::class, 'recipientNidsFilled'))
			->setValue(Server::get(StreamRequest::class), null);
	}

	/** The viewer's `$probe` page, as the timelines read it. */
	private function timeline(string $probe, array $narrowing = []): callable {
		return static function () use ($probe, $narrowing): array {
			$streamRequest = Server::get(StreamRequest::class);
			$streamRequest->setViewer(self::$viewer);
			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_LOCAL)->setProbe($probe)->setLimit(20);
			foreach ($narrowing as $setter => $value) {
				$options->$setter($value);
			}

			return $streamRequest->getTimeline($options);
		};
	}

	/**
	 * The one statement `$block` ran whose SQL matches `$pattern`.
	 *
	 * @return array{sql: string, params: array, types: array}
	 */
	private function capture(callable $block, string $pattern): array {
		$run = QueryCounter::record($block);
		$matching = $run->matching($pattern);
		$this->assertNotEmpty($matching, 'the query under test was not run; ' . $run->summary());

		return $matching[0];
	}

	/**
	 * @param array{sql: string, params: array, types: array} $query
	 * @param array<string, string|null> $guarded alias => the index it must be
	 *                                            read through on SQLite and
	 *                                            MySQL, or null for any index
	 * @param string $sorts why the query is allowed to sort on MySQL; '' when it
	 *                      must not
	 */
	private function assertIndexed(array $query, array $guarded, string $sorts = ''): void {
		$connection = QueryCounter::doctrineConnection();
		$prefix = match (self::$platform) {
			'sqlite' => 'EXPLAIN QUERY PLAN ',
			default => 'EXPLAIN ',
		};
		if (self::$platform === 'pgsql') {
			$connection->executeStatement('SET enable_seqscan = off');
		}
		try {
			$rows = $connection->executeQuery($prefix . $query['sql'], $query['params'], $query['types'])
				->fetchAllAssociative();
		} finally {
			if (self::$platform === 'pgsql') {
				$connection->executeStatement('RESET enable_seqscan');
			}
		}
		$plan = $query['sql'] . "\n" . implode("\n", array_map(
			static fn (array $row): string => implode(' | ', array_map(static fn ($v): string => (string)$v, $row)), $rows
		));

		foreach ($guarded as $alias => $index) {
			match (self::$platform) {
				'sqlite' => $this->assertSqliteSearches($rows, $alias, $index, $plan),
				'mysql' => $this->assertMysqlUsesKey($rows, $alias, $index, $plan),
				'pgsql' => $this->assertNoPostgresSeqScan($rows, $alias, $plan),
			};
		}

		if (self::$platform === 'mysql' && $sorts === '') {
			foreach ($rows as $row) {
				$this->assertDoesNotMatchRegularExpression(
					'/Using (filesort|temporary)/', (string)($row['Extra'] ?? ''), 'sorts outside an index: ' . $plan
				);
			}
		}
	}

	private function assertSqliteSearches(array $rows, string $alias, ?string $index, string $plan): void {
		$details = array_map(static fn (array $row): string => (string)$row['detail'], $rows);
		$quoted = preg_quote($alias, '/');
		foreach ($details as $detail) {
			$this->assertDoesNotMatchRegularExpression('/^SCAN ' . $quoted . '\b/', $detail, $alias . ' is read in full: ' . $plan);
		}

		$through = $index === null ? '\S+' : preg_quote($index, '/');
		$this->assertNotEmpty(
			preg_grep('/^SEARCH ' . $quoted . ' USING (COVERING )?INDEX ' . $through . '\b/', $details),
			$alias . ' is not searched through ' . ($index ?? 'an index') . ': ' . $plan
		);
	}

	private function assertMysqlUsesKey(array $rows, string $alias, ?string $index, string $plan): void {
		$steps = array_values(array_filter($rows, static fn (array $row): bool => ($row['table'] ?? '') === $alias));
		$this->assertNotEmpty($steps, $alias . ' does not appear in the plan: ' . $plan);
		foreach ($steps as $step) {
			$this->assertNotContains($step['type'] ?? '', ['ALL', 'index'], $alias . ' is read in full: ' . $plan);
			$this->assertNotEmpty($step['key'] ?? '', $alias . ' is read without a key: ' . $plan);
			if ($index !== null) {
				$this->assertSame($index, $step['key'], $alias . ' is read through another key: ' . $plan);
			}
		}
	}

	private function assertNoPostgresSeqScan(array $rows, string $alias, string $plan): void {
		foreach ($rows as $row) {
			$line = (string)reset($row);
			if (preg_match('/Seq Scan on (\S+)(?: (\w+))?/', $line, $match) !== 1) {
				continue;
			}
			$scanned = ($match[2] ?? '') !== '' ? $match[2] : $match[1];
			$this->assertNotSame($alias, $scanned, $alias . ' is read in full: ' . $plan);
		}
	}

	/**
	 * `$alias` is read only inside a subquery of the statement, never as one
	 * of the tables its rows are joined from.
	 *
	 * MySQL and MariaDB may run the subquery per row (DEPENDENT SUBQUERY),
	 * once (SUBQUERY), once into a temporary table that is probed
	 * (MATERIALIZED), or as a semi-join that takes at most one of its rows
	 * per outer row (FirstMatch, LooseScan): none repeats the outer rows.
	 * A plain join, or a semi-join that does and weeds the duplicates out
	 * afterwards (Start/End temporary), is refused.
	 *
	 * @param array{sql: string, params: array, types: array} $query
	 */
	private function assertReadOnlyInASubquery(array $query, string $alias): void {
		$connection = QueryCounter::doctrineConnection();
		$prefix = match (self::$platform) {
			'sqlite' => 'EXPLAIN QUERY PLAN ',
			'mysql' => 'EXPLAIN ',
			'pgsql' => 'EXPLAIN (FORMAT JSON) ',
		};
		$rows = $connection->executeQuery($prefix . $query['sql'], $query['params'], $query['types'])
			->fetchAllAssociative();
		$plan = $query['sql'] . "\n" . json_encode($rows, JSON_PRETTY_PRINT);

		$found = 0;
		if (self::$platform === 'mysql') {
			foreach ($rows as $row) {
				if (($row['table'] ?? '') === $alias) {
					$found++;
					$selectType = (string)$row['select_type'];
					$apart = str_contains($selectType, 'SUBQUERY') || $selectType === 'MATERIALIZED'
						|| preg_match('/\\b(FirstMatch|LooseScan)\\b/', (string)($row['Extra'] ?? '')) === 1;
					$this->assertTrue($apart, $alias . ' is joined: ' . $plan);
				}
			}
		} elseif (self::$platform === 'sqlite') {
			$byId = array_column($rows, null, 'id');
			foreach ($rows as $row) {
				if (preg_match('/^(SEARCH|SCAN) ' . preg_quote($alias, '/') . '\b/', (string)$row['detail']) !== 1) {
					continue;
				}
				$found++;
				$inSubquery = false;
				for ($parent = $byId[$row['parent']] ?? null; $parent !== null; $parent = $byId[$parent['parent']] ?? null) {
					$inSubquery = $inSubquery || str_contains((string)$parent['detail'], 'SUBQUERY');
				}
				$this->assertTrue($inSubquery, $alias . ' is joined: ' . $plan);
			}
		} else {
			$walk = function (array $node, bool $inSubplan) use (&$walk, &$found, $alias, $plan): void {
				$inSubplan = $inSubplan || in_array($node['Parent Relationship'] ?? '', ['SubPlan', 'InitPlan'], true);
				if (($node['Alias'] ?? '') === $alias) {
					$found++;
					$this->assertTrue($inSubplan, $alias . ' is joined: ' . $plan);
				}
				foreach ($node['Plans'] ?? [] as $child) {
					$walk($child, $inSubplan);
				}
			};
			$walk(json_decode((string)reset($rows[0]), true)[0]['Plan'], false);
		}
		$this->assertGreaterThan(0, $found, $alias . ' does not appear in the plan: ' . $plan);
	}

	/**
	 * The home page off the recipient rows: one index range per collection
	 * followed, read from `social_sd_atn` without touching the table.
	 */
	public function testTheHomeFastPathReadsTheRecipientIndexOnly(): void {
		$this->useHomeFastPath(true);
		$query = $this->capture(
			$this->timeline(ProbeOptions::HOME),
			'/FROM [`"]?\w*social_stream_dest[`"]? [`"]?sd[`"]? WHERE \([`"]?sd[`"]?\.[`"]?actor_id[`"]? IN/'
		);

		$this->assertIndexed(
			$query, ['sd' => 'social_sd_atn'],
			// StreamTimelines::homeTimelineNidsFromRecipients(): across several
			// collections the database reads every entry the predicate admits
			// and sorts them; the time window is what bounds the read
			'several index ranges, merged by a sort of what the window admits'
		);
	}

	/**
	 * The old page query, still what answers a narrowed home timeline and an
	 * instance whose backfill has not finished: it may sort, it may not read
	 * a table in full.
	 */
	public function testTheHomeJoinPathReadsEveryTableThroughAnIndex(): void {
		$this->useHomeFastPath(false);
		$query = $this->capture($this->timeline(ProbeOptions::HOME), '/^SELECT DISTINCT [`"]?s[`"]?\.[`"]?nid[`"]?/');

		$this->assertIndexed(
			$query, ['s' => null, 'sd' => null, 'f' => null],
			'the documented cost of the fallback: DISTINCT over the follow join, then a sort'
		);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function mediaNarrowings(): array {
		return [
			'Photos' => [['setOnlyMedia' => true]],
			'Videos' => [['setOnlyVideo' => true]],
			// `(short_kind, nid)`, one column and no fallback
			'Shorts' => [['setOnlyShort' => true]],
		];
	}

	/**
	 * Photos and Videos: the `OR` that keeps a row the `media_kind` backfill
	 * has not reached is exactly the shape that makes a planner give up on an
	 * index, so each timeline that can be narrowed is asked. Shorts are asked
	 * the same, over their own column.
	 *
	 * @param array<string, mixed> $narrowing
	 */
	#[DataProvider('mediaNarrowings')]
	public function testANarrowedTimelineReadsNoTableInFull(array $narrowing): void {
		$this->useHomeFastPath(true);
		$sorts = 'the page is chosen among the narrowed rows and sorted';

		$this->assertIndexed(
			$this->capture($this->timeline(ProbeOptions::PUBLIC, $narrowing), '/^SELECT [`"]?s[`"]?\.[`"]?nid[`"]?/'),
			['s' => null, 'sd' => null, 'ca' => null], $sorts
		);
		$this->assertIndexed(
			$this->capture($this->timeline(ProbeOptions::HOME, $narrowing), '/^SELECT DISTINCT [`"]?s[`"]?\.[`"]?nid[`"]?/'),
			['s' => null, 'sd' => null, 'f' => null], $sorts
		);
		$this->assertIndexed(
			$this->capture(
				$this->timeline(ProbeOptions::ACCOUNT, $narrowing + ['setAccountId' => self::REMOTE . '/users/author0']),
				'/^SELECT DISTINCT [`"]?s[`"]?\.[`"]?nid[`"]?/'
			),
			['s' => null, 'sd' => null], $sorts
		);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function accountPages(): array {
		return [
			'posts only, as the outbox and an export read it' => [[]],
			'posts and boosts, as accounts/{id}/statuses reads it' => [['setWithBoosts' => true]],
			'without replies to others' => [['setWithBoosts' => true, 'setExcludeReplies' => true]],
			'one hashtag' => [['setTagged' => 'tag0']],
		];
	}

	/** @param array<string, mixed> $narrowing */
	#[DataProvider('accountPages')]
	public function testAnAccountsStatusesAreReadThroughTheAuthorIndex(array $narrowing): void {
		$query = $this->capture(
			$this->timeline(ProbeOptions::ACCOUNT, ['setAccountId' => self::REMOTE . '/users/author0'] + $narrowing),
			'/^SELECT DISTINCT [`"]?s[`"]?\.[`"]?nid[`"]?/'
		);

		$this->assertIndexed(
			$query, ['s' => null, 'sd' => null] + (isset($narrowing['setExcludeReplies']) ? ['rp' => null] : []) + (isset($narrowing['setTagged']) ? ['st' => null] : []),
			'the DISTINCT over the visibility join'
		);
	}

	/**
	 * The hashtag page: driven from the tag, every table reached through an
	 * index, and the viewer's follows probed per recipient row rather than
	 * joined. Joined, they repeat each candidate row once per account the
	 * viewer follows before the public branch of the visibility rule admits
	 * it — what made this page read 1.1 million rows on MySQL for 1,250
	 * tagged posts and a viewer following 300 accounts.
	 */
	public function testTheHashtagPageProbesTheFollowsInsteadOfJoiningThem(): void {
		$query = $this->capture(
			$this->timeline(ProbeOptions::HASHTAG, ['setArgument' => self::TAG]),
			'/^SELECT DISTINCT [`"]?s[`"]?\.[`"]?nid[`"]? FROM .*social_stream_tag/'
		);

		$this->assertIndexed(
			$query, ['st' => null, 's' => null, 'sd' => null, 'f' => null],
			'the DISTINCT over the recipient rows, then the sort by nid'
		);
		$this->assertReadOnlyInASubquery($query, 'f');
	}

	public function testTheNotificationPageIsReadThroughTheRecipientIndex(): void {
		$query = $this->capture($this->timeline(ProbeOptions::NOTIFICATIONS), '/^SELECT [`"]?s[`"]?\.[`"]?nid[`"]? FROM/');

		$this->assertIndexed($query, ['sd' => null, 's' => null], 'the page is ordered by the post, the read driven by the recipient');
	}

	/**
	 * What the delivery cron reads every minute: the due rows of the queue,
	 * never the abandoned history beside them.
	 */
	public function testTheDeliveryQueueReadsOnlyTheStandbyRows(): void {
		$query = $this->capture(
			static fn (): array => Server::get(RequestQueueRequest::class)->getStandby(),
			'/^SELECT .*social_req_queue/'
		);

		$this->assertIndexed($query, ['rq' => null], 'priority desc, tries asc: no single index order serves both');
	}

	/**
	 * Who a post is delivered to: read by the followed account, not by
	 * scanning follows.
	 *
	 * Asked for an author the viewer follows rather than for the viewer: the
	 * seed's follows are nearly all the viewer's followers, and for a value
	 * that matches most of a table MySQL rightly reads the table in full.
	 */
	public function testFollowerInboxesAreReadThroughTheFollowedIndex(): void {
		$query = $this->capture(
			static fn (): array => Server::get(FollowsRequest::class)->getFollowerInboxes(self::REMOTE . '/users/author0'),
			'/shared_inbox/'
		);

		$this->assertIndexed($query, ['f' => null, 'ca' => null], 'DISTINCT over the inboxes');
	}

	/** Every mention, every search by handle, every WebFinger answer. */
	public function testAnAccountIsLookedUpThroughItsLowercasedHandle(): void {
		$query = $this->capture(
			static function (): void {
				try {
					Server::get(CacheActorsRequest::class)->getFromAccount('QPAuthor1@remote.example');
				} catch (CacheActorDoesNotExistException) {
				}
			},
			'/account_lower/'
		);

		$this->assertIndexed($query, ['ca' => 'social_ca_al']);
	}
}
