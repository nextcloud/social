<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use DateTime;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * `StreamRequest::getPublishedStreamsDueForCounts()`: the cron's pick of this
 * instance's own posts that have a Bluesky record and whose counts are due.
 */
#[AllowMockObjectsWithoutExpectations]
class PublishedStreamsDueForCountsTest extends TestCase {
	public function testLocalPostsWithAPostRecordPublishedSinceAndDueAreRead(): void {
		$due = new DateTime('@1790000000');
		$since = new DateTime('@1789400000');
		$where = [];
		$params = [];
		$qb = $this->createMock(SocialQueryBuilder::class);
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(static function (mixed $value) use (&$params): string {
			$params[] = $value;

			return ':p' . count($params);
		});
		$qb->method('andWhere')->willReturnCallback(static function (string $predicate) use ($qb, &$where) {
			$where[] = $predicate;

			return $qb;
		});
		$qb->expects($this->once())->method('limitToLocal')->with(true);
		$qb->expects($this->once())->method('limitToDBFieldDateTime')->with('counts_at', $due, true);
		$qb->expects($this->once())->method('innerJoin')
			->with('s', CoreRequestBuilder::TABLE_ATPROTO_RECORD, 'rec', '(rec.local_id_prim = s.id_prim AND rec.collection = :p2)')
			->willReturnSelf();
		$qb->expects($this->once())->method('linkToCacheActors')->with('ca', 's.attributed_to_prim');
		$qb->expects($this->once())->method('orderBy')->with('s.published_time', 'asc')->willReturnSelf();
		$qb->expects($this->once())->method('setMaxResults')->with(50)->willReturnSelf();

		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['getStreamSelectSql', 'getStreamsFromRequest'])
			->getMock();
		$request->method('getStreamSelectSql')->willReturn($qb);
		$request->expects($this->once())->method('getStreamsFromRequest')->with($qb)->willReturn([]);

		$this->assertSame([], $request->getPublishedStreamsDueForCounts($due, $since, 50));
		$this->assertSame(['s.published_time >= :p1'], $where);
		$this->assertSame([$since, RecordMapper::POST], $params);
	}
}
