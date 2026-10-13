<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\AtprotoEventRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The repository figures of the admin page: the records written in a
 * window, counted from the first id of the window on, because the record
 * table has no index on its creation time.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoRepoFiguresTest extends TestCase {
	/** @var string[] */
	private array $where = [];

	/**
	 * A record table with gaps where records were deleted: id => creation.
	 *
	 * @param array<int, int> $rows
	 */
	private static function table(array $rows): AtprotoRepoRequest {
		return new class($rows) extends AtprotoRepoRequest {
			public int $lookups = 0;

			/** @param array<int, int> $rows */
			public function __construct(
				private array $rows,
			) {
			}

			#[\Override]
			public function lastRecordId(): int {
				return $this->rows === [] ? 0 : max(array_keys($this->rows));
			}

			#[\Override]
			public function recordAtOrAfter(int $id): ?array {
				$this->lookups++;
				ksort($this->rows);
				foreach ($this->rows as $at => $creation) {
					if ($at >= $id) {
						return ['id' => $at, 'creation' => $creation];
					}
				}

				return null;
			}
		};
	}

	/**
	 * @return iterable<string, array{array<int, int>, int, int}>
	 */
	public static function windows(): iterable {
		$rows = [1 => 100, 2 => 100, 5 => 200, 6 => 250, 9 => 300, 12 => 300, 40 => 400];
		yield 'the window starts on a row' => [$rows, 250, 6];
		yield 'the window starts between rows' => [$rows, 220, 6];
		yield 'equal times: the first of them' => [$rows, 300, 9];
		yield 'everything is in the window' => [$rows, 50, 1];
		yield 'only the last row' => [$rows, 400, 40];
		yield 'nothing is in the window' => [$rows, 401, 0];
		yield 'an empty table' => [[], 0, 0];
	}

	/** @param array<int, int> $rows */
	#[DataProvider('windows')]
	public function testTheFirstIdOfTheWindowIsFound(array $rows, int $since, int $first): void {
		$this->assertSame($first, self::table($rows)->firstRecordIdSince($since));
	}

	/** A big table costs a few dozen lookups, not one per row. */
	public function testTheSearchIsLogarithmic(): void {
		$rows = [];
		for ($id = 1; $id <= 100000; $id++) {
			$rows[$id] = $id;
		}
		$table = self::table($rows);

		$this->assertSame(73123, $table->firstRecordIdSince(73123));
		$this->assertLessThan(25, $table->lookups);
	}

	private function counting(int $from, int $count): AtprotoRepoRequest&MockObject {
		$qb = $this->createMock(SocialQueryBuilder::class);
		foreach (['select', 'from'] as $method) {
			$qb->method($method)->willReturnCallback(static fn (): SocialQueryBuilder => $qb);
		}
		$qb->method('expr')->willReturn(new FakeExpressions());
		$functions = $this->createStub(IFunctionBuilder::class);
		$functions->method('count')->willReturn($this->createStub(IQueryFunction::class));
		$qb->method('func')->willReturn($functions);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => "'" . $value . "'");
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function ($clause) use (&$qb): SocialQueryBuilder {
				$this->where[] = (string)$clause;

				return $qb;
			});
		}
		$result = $this->createStub(IResult::class);
		$result->method('fetch')->willReturn(['n' => (string)$count]);
		$qb->method('executeQuery')->willReturn($result);

		$request = $this->getMockBuilder(AtprotoRepoRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder', 'firstRecordIdSince'])
			->getMock();
		$request->method('getQueryBuilder')->willReturn($qb);
		$request->method('firstRecordIdSince')->willReturn($from);

		return $request;
	}

	/**
	 * The count reads the window by primary key and keeps the records
	 * written here: one a moving repository brought has no local object.
	 */
	public function testTheCountReadsTheWindowByIdAndSkipsMovedRecords(): void {
		$count = $this->counting(812, 5)->countRecordsWrittenSince('app.bsky.feed.post', 1000);

		$this->assertSame(5, $count);
		$this->assertSame(["id >= '812'", "collection = 'app.bsky.feed.post'", "local_id_prim <> ''"], $this->where);
	}

	public function testAnEmptyWindowIsNotCounted(): void {
		$request = $this->counting(0, 99);
		$request->expects($this->never())->method('getQueryBuilder');

		$this->assertSame(0, $request->countRecordsWrittenSince('app.bsky.feed.post', 1000));
	}

	/** The sums come from the heads, one row per local account. */
	public function testTheRepositorySumsAreReadFromTheHeads(): void {
		$from = [];
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('selectAlias')->willReturnCallback(static fn (): SocialQueryBuilder => $qb);
		$qb->method('from')->willReturnCallback(static function (string $table) use (&$from, $qb): SocialQueryBuilder {
			$from[] = $table;

			return $qb;
		});
		$functions = $this->createStub(IFunctionBuilder::class);
		$functions->method('sum')->willReturn($this->createStub(IQueryFunction::class));
		$qb->method('func')->willReturn($functions);
		$result = $this->createStub(IResult::class);
		$result->method('fetch')->willReturn(['records' => '1234', 'blob_bytes' => '987654321']);
		$qb->method('executeQuery')->willReturn($result);
		$request = $this->getMockBuilder(AtprotoRepoRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturn($qb);

		$this->assertSame(['records' => 1234, 'blob_bytes' => 987654321], $request->sumHeads());
		$this->assertSame(['social_atproto_repo'], $from);
	}

	/** The newest frame is the one with the highest sequence number. */
	public function testTheNewestEventTimeIsTheHighestSequence(): void {
		$order = [];
		$max = null;
		$qb = $this->createMock(SocialQueryBuilder::class);
		foreach (['select', 'from'] as $method) {
			$qb->method($method)->willReturnCallback(static fn (): SocialQueryBuilder => $qb);
		}
		$qb->method('orderBy')->willReturnCallback(static function (string $sort, ?string $direction = null) use (&$order, $qb): SocialQueryBuilder {
			$order[] = $sort . ' ' . $direction;

			return $qb;
		});
		$qb->method('setMaxResults')->willReturnCallback(static function (?int $limit) use (&$max, $qb): SocialQueryBuilder {
			$max = $limit;

			return $qb;
		});
		$result = $this->createStub(IResult::class);
		$result->method('fetchOne')->willReturn('1760000000');
		$qb->method('executeQuery')->willReturn($result);
		$request = $this->getMockBuilder(AtprotoEventRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getQueryBuilder'])
			->getMock();
		$request->method('getQueryBuilder')->willReturn($qb);

		$this->assertSame(1760000000, $request->latestTime());
		$this->assertSame(['seq desc'], $order);
		$this->assertSame(1, $max);
	}
}
