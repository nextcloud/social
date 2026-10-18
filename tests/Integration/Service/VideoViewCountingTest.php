<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Service;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\VideoViewsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\VideoViewService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ISession;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Counting the views of a video posted here against the real database: once
 * per viewer and day, and nothing left behind about who watched once the day
 * has been swept.
 */
class VideoViewCountingTest extends TestCase {
	private const VIDEO = 'https://itest.example/videocount/1';
	private const AUTHOR = 'https://itest.example/users/videocount-author';
	private const BOB = 'https://itest.example/users/videocount-bob';

	private int $now = 1_800_000_000;
	private string $sessionId = 'itest-session-one';

	protected function setUp(): void {
		parent::setUp();
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
			$qb->delete($table)->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter(md5(self::VIDEO))));
			$qb->executeStatement();
		}
	}

	private function service(): VideoViewService {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$session = $this->createStub(ISession::class);
		$session->method('getId')->willReturnCallback(fn (): string => $this->sessionId);

		return new VideoViewService(
			Server::get(VideoViewsRequest::class), Server::get(IConfig::class), $session, $time, new NullLogger()
		);
	}

	private function video(): Note {
		$note = new Note();
		$note->setId(self::VIDEO);
		$note->setAttributedTo(self::AUTHOR);
		$note->setLocal(true);
		$note->setAttachments([(new MediaAttachment())->import([
			'id' => '1', 'type' => 'video', 'media_type' => 'video/mp4', 'url' => 'https://itest.example/media/1',
		])]);

		return $note;
	}

	private function bob(): Person {
		$bob = new Person();
		$bob->setId(self::BOB);

		return $bob;
	}

	private function views(): int {
		$video = $this->video();
		$this->service()->attach([$video]);

		return (int)$video->getLocalVideoViews();
	}

	/** @return list<array<string, mixed>> the dedupe rows of the test video */
	private function seenRows(): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('*')->from(CoreRequestBuilder::TABLE_VIDEO_SEEN)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter(md5(self::VIDEO))));

		return $qb->executeQuery()->fetchAll();
	}

	public function testEachViewerCountsOnceADay(): void {
		$service = $this->service();
		$this->assertSame(0, $this->views(), 'a video nobody watched has a count of nothing');

		$service->count($this->video(), $this->bob());
		$service->count($this->video(), $this->bob());
		$service->count($this->video(), null);
		$service->count($this->video(), null);
		$this->sessionId = 'itest-session-two';
		$service->count($this->video(), null);
		$this->assertSame(3, $this->views(), 'bob, and two people who are not signed in');

		$this->now += 86400;
		$service->count($this->video(), $this->bob());
		$this->assertSame(4, $this->views(), 'the next day bob counts again');
	}

	/**
	 * What is stored about a viewer names nobody, and once the day is swept
	 * nothing about who watched is left — only the number.
	 */
	public function testNothingAboutWhoWatchedOutlivesTheDay(): void {
		$service = $this->service();
		$service->count($this->video(), $this->bob());
		$service->count($this->video(), null);

		$rows = $this->seenRows();
		$this->assertCount(2, $rows);
		foreach ($rows as $row) {
			$stored = implode('|', array_map('strval', $row));
			$this->assertStringNotContainsString(self::BOB, $stored);
			$this->assertStringNotContainsString(md5(self::BOB), $stored);
			$this->assertStringNotContainsString($this->sessionId, $stored);
			$this->assertSame(['day', 'id', 'stream_id_prim', 'viewer_hash'], self::sortedKeys($row));
		}

		$this->now += 86400;
		$service->forgetEarlierDays();

		$this->assertSame([], $this->seenRows());
		$this->assertSame(2, $this->views());
	}

	/** @return list<string> */
	private static function sortedKeys(array $row): array {
		$keys = array_map('strval', array_keys($row));
		sort($keys);

		return $keys;
	}

	public function testTheAuthorIsNotCounted(): void {
		$author = new Person();
		$author->setId(self::AUTHOR);

		$this->assertFalse($this->service()->count($this->video(), $author));
		$this->assertSame(0, $this->views());
	}
}
