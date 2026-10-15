<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\RemoteCountService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Which remote posts the cron asks about again
 * (`StreamRequest::getRemoteStreamsDueForCounts()`), against the real
 * database: the posts of the window that are due, never asked first and then
 * the one asked longest ago, with no boost, no local post, nothing older than
 * the window and nothing stamped ahead.
 *
 * The posts are published in this run's own second, decades ahead, so a
 * window starting there holds this test's posts alone.
 */
class RemoteCountsDueTest extends TestCase {
	private const AUTHOR = 'https://remote.example/countsdue/users/bob';
	private const LOCAL = 'https://cloud.example.org/countsdue/users/alice';

	private const SUFFIXES = ['old', 'never-a', 'never-b', 'asked-long', 'asked-less', 'asked-recently', 'gone', 'boost', 'local'];

	private StreamRequest $streamRequest;
	private int $second;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->second = 5_000_000_000 + random_int(0, 100_000) * 100;
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById($this->id($suffix));
		}
	}

	private function id(string $suffix): string {
		return ($suffix === 'local' ? self::LOCAL : self::AUTHOR) . '/statuses/countsdue-' . $suffix;
	}

	private function post(string $suffix, int $offset, ?int $countedAt = null, ?Stream $post = null): void {
		$post ??= new Note();
		$post->setId($this->id($suffix));
		$post->setAttributedTo($suffix === 'local' ? self::LOCAL : self::AUTHOR);
		$post->setToArray([ACore::CONTEXT_PUBLIC]);
		$post->setLocal($suffix === 'local');
		$post->setContent('<p>' . $suffix . '</p>');
		$post->setPublishedTime($this->second + $offset);
		$post->setPublished(gmdate('Y-m-d\TH:i:s\Z', $this->second + $offset));
		$this->streamRequest->save($post);
		if ($countedAt !== null) {
			$this->streamRequest->markCountsRefreshed($this->id($suffix), new DateTime('@' . $countedAt));
		}
	}

	private function seed(): void {
		$this->post('old', -RemoteCountService::PUBLISHED_WINDOW - 3600);
		$this->post('never-a', 10);
		$this->post('never-b', 20);
		$this->post('asked-long', 30, $this->second - 50_000);
		$this->post('asked-less', 5, $this->second - 20_000);
		$this->post('asked-recently', 40, $this->second + 500);
		$this->post('gone', 50, $this->second + 30 * 86400);
		$boost = new Announce();
		$boost->setObjectId($this->id('never-a'));
		$this->post('boost', 60, null, $boost);
		$this->post('local', 70);
	}

	/**
	 * @param Stream[] $posts
	 * @return list<string>
	 */
	private function suffixes(array $posts): array {
		return array_map(
			static fn (Stream $post): string => substr($post->getId(), strrpos($post->getId(), 'countsdue-') + 10),
			$posts
		);
	}

	private function due(): DateTime {
		return new DateTime('@' . $this->second);
	}

	private function since(): DateTime {
		return new DateTime('@' . ($this->second - RemoteCountService::PUBLISHED_WINDOW));
	}

	public function testThePostsOfTheWindowAreDueNeverAskedFirstThenTheLongestWaiting(): void {
		$this->seed();

		$due = $this->suffixes($this->streamRequest->getRemoteStreamsDueForCounts($this->due(), $this->since()));

		$this->assertCount(4, $due, implode(', ', $due));
		$this->assertEqualsCanonicalizing(['never-a', 'never-b'], array_slice($due, 0, 2), 'never asked comes first');
		$this->assertSame(['asked-long', 'asked-less'], array_slice($due, 2), 'then by when it was last asked');
	}

	public function testABatchTakesTheLongestWaiting(): void {
		$this->seed();

		$due = $this->suffixes($this->streamRequest->getRemoteStreamsDueForCounts($this->due(), $this->since(), 3));

		$this->assertCount(3, $due);
		$this->assertSame('asked-long', $due[2]);
	}

	public function testWithoutAWindowEveryRemotePostIsDue(): void {
		$this->seed();

		$due = $this->suffixes($this->streamRequest->getRemoteStreamsDueForCounts($this->due(), null));

		$this->assertContains('old', $due);
		$this->assertNotContains('boost', $due);
		$this->assertNotContains('gone', $due);
	}
}
