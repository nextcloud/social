<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamViewsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ViewCountService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who is counted, who is told, and what must never happen to a read.
 */
class ViewCountServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';
	private const BOB = 'https://cloud.example/@bob';
	private const POST = 'https://cloud.example/@alice/notes/7';

	private StreamViewsRequest|MockObject $streamViewsRequest;
	private ViewCountService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->streamViewsRequest = $this->createMock(StreamViewsRequest::class);
		$this->service = new ViewCountService($this->streamViewsRequest, new NullLogger());
	}

	private function person(string $id): Person {
		$actor = new Person();
		$actor->setId($id);

		return $actor;
	}

	private function post(): Note {
		$note = new Note();
		$note->setId(self::POST);
		$note->setAttributedTo(self::ALICE);

		return $note;
	}

	public function testAReaderOpeningAPostIsCounted(): void {
		$this->streamViewsRequest->expects($this->once())->method('seen')
			->with(self::POST, self::BOB);

		$this->service->seen($this->post(), $this->person(self::BOB));
	}

	/** Re-reading your own post is not a reader. */
	public function testTheAuthorLookingAtTheirOwnPostIsNotCounted(): void {
		$this->streamViewsRequest->expects($this->never())->method('seen');

		$this->service->seen($this->post(), $this->person(self::ALICE));
	}

	/**
	 * An anonymous reader cannot be counted without keeping something about
	 * them that this app deliberately does not keep.
	 */
	public function testNobodySignedInIsNotCounted(): void {
		$this->streamViewsRequest->expects($this->never())->method('seen');

		$this->service->seen($this->post(), null);
	}

	/** How many people read a post is the author's business. */
	public function testOnlyTheAuthorIsToldTheNumber(): void {
		$this->streamViewsRequest->method('countFor')->willReturn(42);

		$mine = $this->post();
		$this->service->seen($mine, $this->person(self::ALICE));
		$this->assertSame(42, $mine->getViewCount());

		$theirs = $this->post();
		$this->service->seen($theirs, $this->person(self::BOB));
		$this->assertNull($theirs->getViewCount(), 'null, not zero: it is not theirs to know');
	}

	/**
	 * A profile is twenty posts, and twenty queries to put a number under each
	 * is the kind of thing that is invisible in development and is the whole
	 * page in production.
	 */
	public function testAPageOfOwnPostsIsCountedInOneQuery(): void {
		$mine = $this->post();
		$theirs = (new Note())->setId('https://cloud.example/@bob/notes/1');
		$theirs->setAttributedTo(self::BOB);

		$this->streamViewsRequest->expects($this->once())->method('countForMany')
			->with([self::POST])
			->willReturn([md5(self::POST) => 12]);

		$this->service->attachAll([$mine, $theirs], $this->person(self::ALICE));

		$this->assertSame(12, $mine->getViewCount());
		$this->assertNull($theirs->getViewCount());
	}

	/** Somebody opening a post gets the post, whatever the counter does. */
	public function testAFailingCounterNeverFailsTheRead(): void {
		$this->streamViewsRequest->method('seen')
			->willThrowException(new \RuntimeException('the database is down'));

		$this->service->seen($this->post(), $this->person(self::BOB));
		$this->addToAssertionCount(1);
	}
}
