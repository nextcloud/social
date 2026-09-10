<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Hashtag trends against the real database: the grouped count that replaced
 * the hydrated sample, and the ordering that moved out of PHP.
 *
 * The old counting read the thousand most recent posts and counted their tags
 * here, so on a busy instance every window saw the same thousand posts and
 * reported the same number for all five of them.
 */
class HashtagTrendsTest extends TestCase {
	private const BASE = 'https://remote.example/trends';
	private const AUTHOR = self::BASE . '/users/author';
	private const TAGS = ['#itest-steady', '#itest-spike', '#itest-quiet'];

	private StreamRequest $streamRequest;
	private HashtagsRequest $hashtagsRequest;
	private IDBConnection $connection;
	/** @var string[] */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->hashtagsRequest = Server::get(HashtagsRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ($this->created as $id) {
			$this->streamRequest->deleteById($id);
		}
		$this->created = [];

		$qb = $this->connection->getQueryBuilder();
		$qb->delete(CoreRequestBuilder::TABLE_HASHTAGS)->where($qb->expr()->in(
			'hashtag', $qb->createNamedParameter(self::TAGS, IQueryBuilder::PARAM_STR_ARRAY)
		));
		$qb->executeStatement();
	}

	/** @param string[] $hashtags */
	private function note(string $suffix, array $hashtags, int $ago): void {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo('https://www.w3.org/ns/activitystreams#Public');
		$note->setVisibility('public');
		$note->setContent(implode(' ', $hashtags));
		$note->setHashtags($hashtags);
		$note->setPublishedTime(time() - $ago);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);
		$this->created[] = $note->getId();
	}

	public function testEachWindowCountsOnlyWhatFallsInsideIt(): void {
		$this->note('a', ['#itest-steady'], 60);
		$this->note('b', ['#itest-steady'], 5 * 86400);
		$this->note('c', ['#itest-spike'], 120);

		$lastHour = $this->streamRequest->countHashtagsSince(time() - 3600);
		$tenDays = $this->streamRequest->countHashtagsSince(time() - 864000);

		$this->assertSame(1, $lastHour['#itest-steady'] ?? 0);
		$this->assertSame(1, $lastHour['#itest-spike'] ?? 0);
		// the older post is only inside the wider window: the two windows must
		// not report the same number
		$this->assertSame(2, $tenDays['#itest-steady'] ?? 0);
	}

	public function testATagUsedByNoPostInTheWindowIsAbsentRatherThanZero(): void {
		$this->note('a', ['#itest-quiet'], 5 * 86400);

		$this->assertArrayNotHasKey('#itest-quiet', $this->streamRequest->countHashtagsSince(time() - 3600));
	}

	public function testTrendingIsOrderedAndCutByTheDatabase(): void {
		$this->hashtagsRequest->save('#itest-steady', ['1h' => 2, '12h' => 9, '1d' => 40, '3d' => 40, '10d' => 40]);
		$this->hashtagsRequest->save('#itest-spike', ['1h' => 12, '12h' => 12, '1d' => 12, '3d' => 12, '10d' => 12]);
		$this->hashtagsRequest->save('#itest-quiet', ['1h' => 0, '12h' => 0, '1d' => 2, '3d' => 2, '10d' => 2]);

		$hour = array_column($this->hashtagsRequest->getTrending('1h', 10), 'hashtag');
		$day = array_column($this->hashtagsRequest->getTrending('1d', 10), 'hashtag');

		// the window decides the order, and a tag unused within it is left out
		$this->assertSame(
			['#itest-spike', '#itest-steady'],
			array_values(array_intersect($hour, self::TAGS))
		);
		$this->assertSame(
			['#itest-steady', '#itest-spike', '#itest-quiet'],
			array_values(array_intersect($day, self::TAGS))
		);
	}

	public function testTrendingHonoursTheLimitInTheQuery(): void {
		foreach (self::TAGS as $i => $tag) {
			$this->hashtagsRequest->save($tag, ['1h' => 10 - $i, '12h' => 1, '1d' => 1, '3d' => 1, '10d' => 1]);
		}

		$this->assertLessThanOrEqual(2, count($this->hashtagsRequest->getTrending('1h', 2)));
	}

	public function testAnUnknownWindowAsksForNothing(): void {
		$this->assertSame([], $this->hashtagsRequest->getTrending('7y', 10));
	}

	public function testUpdateKeepsTheJsonAndTheCountersInStep(): void {
		$this->hashtagsRequest->save('#itest-steady', ['1h' => 1, '12h' => 1, '1d' => 1, '3d' => 1, '10d' => 1]);
		$this->hashtagsRequest->update('#itest-steady', ['1h' => 7, '12h' => 7, '1d' => 7, '3d' => 7, '10d' => 7]);

		$row = $this->hashtagsRequest->getHashtag('#itest-steady');
		$this->assertSame(7, (int)$row['trend']['1h']);
		$this->assertSame(
			['#itest-steady'],
			array_values(array_intersect(
				array_column($this->hashtagsRequest->getTrending('1h', 10), 'hashtag'),
				self::TAGS
			))
		);
	}
}
