<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\CompactStoredCopies;
use OCA\Social\Tests\Migration\FakeConnection;
use OCA\Social\Tools\Model\Cache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The walk that takes the unread copies out of the rows stored before they
 * stopped being written, against a table held in PHP.
 */
#[AllowMockObjectsWithoutExpectations]
class CompactStoredCopiesTest extends TestCase {
	private const POST = '{"id":"https://a.example/notes/1","type":"Note","content":"hi"}';

	/** @var array<int, array{type: string, cache: string, details: string}> nid => row */
	private array $rows = [];
	public int $updates = 0;
	/** @var array<int, mixed> arguments the job re-queued itself with */
	private array $queued = [];

	public function answer(string $sql, array $params): IResult {
		preg_match('/LIMIT (\d+)/', $sql, $limit);
		ksort($this->rows);
		$page = [];
		foreach ($this->rows as $nid => $row) {
			if ($nid <= (int)$params[0]) {
				continue;
			}
			$page[] = ['nid' => (string)$nid] + $row;
			if (count($page) >= (int)$limit[1]) {
				break;
			}
		}

		$result = $this->createStub(IResult::class);
		$result->method('fetchAll')->willReturn($page);

		return $result;
	}

	public function apply(string $sql, array $params): int {
		$this->updates++;
		$nid = (int)array_pop($params);
		preg_match_all('/`(cache|details)` = \?/', $sql, $columns);
		foreach ($columns[1] as $i => $column) {
			$this->rows[$nid][$column] = $params[$i];
		}

		return 1;
	}

	private function job(): CompactStoredCopies {
		$test = $this;
		$connection = new class($test) extends FakeConnection {
			public function __construct(
				private CompactStoredCopiesTest $test,
			) {
				parent::__construct();
			}

			public function executeQuery(string $sql, ?array $params = null, $types = null): IResult {
				return $this->test->answer($sql, $params ?? []);
			}

			public function executeStatement($sql, ?array $params = null, ?array $types = null): int {
				return $this->test->apply((string)$sql, $params ?? []);
			}
		};
		$jobList = $this->createStub(IJobList::class);
		$jobList->method('add')->willReturnCallback(function (string $class, $argument): void {
			$this->queued[] = $argument;
		});

		return new CompactStoredCopies($this->createStub(ITimeFactory::class), $connection, $jobList, new NullLogger());
	}

	/** A boost's cache as it was written while the decoded copy was. */
	private static function oldCache(): string {
		$url = 'https://a.example/notes/1';

		return json_encode([
			'_items' => [$url],
			'_count' => 1,
			$url => [
				'url' => $url,
				'content' => self::POST,
				'object' => json_decode(self::POST, true),
				'status' => 1,
				'error' => 0,
				'creation' => 1760000000,
			],
		], JSON_UNESCAPED_SLASHES);
	}

	public function testABoostKeepsItsCachedPostOnceAndANotificationOnlyItsOwnDetails(): void {
		$this->rows[1] = ['type' => 'Announce', 'cache' => self::oldCache(), 'details' => '[]'];
		$this->rows[2] = [
			'type' => 'SocialAppNotification',
			'cache' => '',
			'details' => json_encode(['post' => json_decode(self::POST, true), 'actor' => ['id' => 'https://a.example/users/a'], 'mentions' => ['x']]),
		];
		// a post's own details are not a notification's, and are left alone
		$this->rows[3] = ['type' => 'Note', 'cache' => '', 'details' => '{"post":1}'];
		// already compact: not written again
		$this->rows[4] = ['type' => 'SocialAppNotification', 'cache' => '', 'details' => '{"mentions":[]}'];

		$this->assertNull($this->job()->compact('0', 5));

		$this->assertSame(2, $this->updates);
		$cache = json_decode($this->rows[1]['cache'], true);
		$this->assertArrayNotHasKey('object', $cache['https://a.example/notes/1']);
		$read = new Cache();
		$read->import($cache);
		$this->assertSame(json_decode(self::POST, true), $read->getItem('https://a.example/notes/1')->getObject());
		$this->assertSame(['mentions' => ['x']], json_decode($this->rows[2]['details'], true));
		$this->assertSame('{"post":1}', $this->rows[3]['details']);
	}

	public function testARunThatDoesNotReachTheEndQueuesItselfWhereItStopped(): void {
		$count = CompactStoredCopies::PAGE * CompactStoredCopies::PAGES_PER_RUN + 1;
		for ($i = 1; $i <= $count; $i++) {
			$this->rows[$i] = ['type' => 'Note', 'cache' => '', 'details' => '[]'];
		}
		$this->rows[$count] = ['type' => 'Announce', 'cache' => self::oldCache(), 'details' => '[]'];

		$job = $this->job();
		(new \ReflectionMethod(CompactStoredCopies::class, 'run'))->invoke($job, ['after' => '0']);

		$this->assertSame([['after' => (string)($count - 1)]], $this->queued);
		$this->assertSame(0, $this->updates);

		(new \ReflectionMethod(CompactStoredCopies::class, 'run'))->invoke($job, $this->queued[0]);

		$this->assertSame(1, $this->updates);
		$this->assertStringNotContainsString('"object"', $this->rows[$count]['cache']);
		$this->assertCount(1, $this->queued);
	}
}
