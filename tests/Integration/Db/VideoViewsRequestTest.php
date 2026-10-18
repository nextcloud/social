<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\VideoViewsRequest;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The view count of a video posted here and today's dedupe behind it, on the
 * real unique indexes: a second view the same day must be a no-op rather than
 * an error, which on PostgreSQL would abort the transaction it runs in.
 */
class VideoViewsRequestTest extends TestCase {
	private const VIDEO = 'https://itest.example/videoviews/1';
	private const OTHER = 'https://itest.example/videoviews/2';
	private const DAY = 20000;

	private VideoViewsRequest $views;

	protected function setUp(): void {
		parent::setUp();
		$this->views = Server::get(VideoViewsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$db = Server::get(IDBConnection::class);
		foreach ([CoreRequestBuilder::TABLE_VIDEO_VIEWS, CoreRequestBuilder::TABLE_VIDEO_SEEN] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in('stream_id_prim', $qb->createNamedParameter([md5(self::VIDEO), md5(self::OTHER)], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	/** @return int how many dedupe rows this test's videos have */
	private function seenRows(): int {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('id')->from(CoreRequestBuilder::TABLE_VIDEO_SEEN)
			->where($qb->expr()->in('stream_id_prim', $qb->createNamedParameter([md5(self::VIDEO), md5(self::OTHER)], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));

		return count($qb->executeQuery()->fetchAll());
	}

	public function testAViewerIsSeenOncePerVideoAndHash(): void {
		$this->assertTrue($this->views->seen(self::VIDEO, str_repeat('a', 64), self::DAY));
		$this->assertFalse($this->views->seen(self::VIDEO, str_repeat('a', 64), self::DAY), 'a second time is a no-op, not an error');
		$this->assertTrue($this->views->seen(self::OTHER, str_repeat('a', 64), self::DAY));
		$this->assertTrue($this->views->seen(self::VIDEO, str_repeat('b', 64), self::DAY));

		$this->assertSame(3, $this->seenRows());
	}

	public function testTheCountMovesByOneAndIsReadForAPageAtOnce(): void {
		$this->views->increment(self::VIDEO);
		$this->views->increment(self::VIDEO);
		$this->views->increment(self::OTHER);

		$this->assertSame(
			[md5(self::VIDEO) => 2, md5(self::OTHER) => 1],
			$this->views->countsFor([self::VIDEO, self::OTHER, 'https://itest.example/videoviews/never'])
		);
		$this->assertSame([], $this->views->countsFor([]));
	}

	/** The days before go; today's rows stay, and so does the number. */
	public function testOnlyTheDaysBeforeAreForgotten(): void {
		$this->views->seen(self::VIDEO, str_repeat('a', 64), self::DAY - 1);
		$this->views->seen(self::VIDEO, str_repeat('b', 64), self::DAY);
		$this->views->increment(self::VIDEO);
		$this->views->increment(self::VIDEO);

		$this->views->forgetBefore(self::DAY);

		$this->assertSame(1, $this->seenRows());
		$this->assertSame([md5(self::VIDEO) => 2], $this->views->countsFor([self::VIDEO]));
	}
}
