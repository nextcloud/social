<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Counts;

use DateTime;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCA\Social\Service\Counts\CountWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What storing a network's totals writes: the origin's halves into
 * `details`, and the totals through the recount that adds this instance's
 * own share inside the statement that stores them.
 */
#[AllowMockObjectsWithoutExpectations]
class CountWriterTest extends TestCase {
	private const POST = 'https://remote.example/notes/1';
	private const NOW = 1790000000;

	private StreamRequest&MockObject $streams;
	private ActionsRequest&MockObject $actions;
	/** @var array<string, mixed> what the last `changeDetails()` stored */
	private array $written = [];

	protected function setUp(): void {
		$this->streams = $this->createMock(StreamRequest::class);
		$this->actions = $this->createMock(ActionsRequest::class);
		$this->actions->method('countActions')->willReturnCallback(static fn (string $id, string $type): int => $type === Like::TYPE ? 2 : ($type === Announce::TYPE ? 1 : 0));
		$this->streams->method('countRepliesTo')->willReturn(1);
		$this->streams->method('countQuotesOf')->willReturn(1);
	}

	private function writer(): CountWriter {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new CountWriter($this->streams, $this->actions, $time);
	}

	/**
	 * `changeDetails()` as the database answers it: `$change` applied to the
	 * blob as stored, which is `$stored` — or what the post read, where a test
	 * gives none — and what it returned is what is stored now.
	 *
	 * @param ?array<string, mixed> $stored
	 * @param list<string> $calls
	 */
	private function storing(Note $post, ?array $stored = null, array &$calls = []): void {
		$this->streams->method('changeDetails')->willReturnCallback(
			function (string $id, callable $change) use ($post, $stored, &$calls): array {
				$this->assertSame(self::POST, $id);
				$calls[] = 'details';
				$this->written = $change($stored ?? $post->getDetailsAll());

				return $this->written;
			}
		);
	}

	public function testTheOriginsTotalsAreStoredAsTheirHalvesAndThenRecounted(): void {
		$post = new Note();
		$post->setId(self::POST);
		$this->streams->method('getStreamById')->with(self::POST)->willReturn($post);

		$calls = [];
		$this->storing($post, null, $calls);
		$this->streams->expects($this->once())->method('markCountsRefreshed')
			->with(self::POST, $this->callback(static fn (DateTime $at): bool => $at->getTimestamp() === self::NOW));
		$this->streams->expects($this->once())->method('recount')
			->with($this->identicalTo($post), Details::LIKES, Details::BOOSTS, Details::REPLIES, Details::QUOTES)
			->willReturnCallback(function () use (&$calls, $post): void {
				$calls[] = 'recount';
				$this->assertSame(8, $post->getDetailInt(Details::REMOTE_LIKES));
				$this->assertSame(3, $post->getDetailInt(Details::REMOTE_BOOSTS));
				$this->assertSame(2, $post->getDetailInt(Details::REMOTE_REPLIES));
			});

		$this->assertTrue($this->writer()->write(self::POST, 10, 4, 3));
		$this->assertSame(['details', 'recount'], $calls, 'the halves are stored before the recount that adds to them');
		$this->assertSame(8, $this->written[Details::REMOTE_LIKES]);
	}

	public function testTheQuotesTheOriginCountsAreStoredAsTheirHalfToo(): void {
		$post = new Note();
		$post->setId(self::POST);
		$this->streams->method('getStreamById')->willReturn($post);
		$this->storing($post);

		$this->writer()->write(self::POST, null, null, null, [], 5);

		$this->assertSame(4, $post->getDetailInt(Details::REMOTE_QUOTES), 'the one quote held here is added by the recount');
	}

	public function testACountTheOriginDoesNotStateKeepsWhatWasStored(): void {
		$post = new Note();
		$post->setId(self::POST);
		$post->setDetailInt(Details::REMOTE_REPLIES, 7);
		$post->setDetailInt(Details::BOOSTS, 6);
		$this->streams->method('getStreamById')->willReturn($post);
		$this->storing($post);

		$this->writer()->write(self::POST, 2, null, null);

		$this->assertSame(0, $post->getDetailInt(Details::REMOTE_LIKES), 'never below zero');
		$this->assertSame(7, $post->getDetailInt(Details::REMOTE_REPLIES));
		$this->assertSame(5, $post->getDetailInt(Details::REMOTE_BOOSTS), 'the total on the row less what is held here');
	}

	public function testANetworksOwnBlockIsMergedIntoWhatThePostHolds(): void {
		$post = new Note();
		$post->setId(self::POST);
		$post->setDetailArray(Details::ATPROTO, ['uri' => 'at://x', 'quotes' => 1]);
		$this->streams->method('getStreamById')->willReturn($post);
		$this->storing($post);

		$this->writer()->write(self::POST, 1, 1, 1, [Details::ATPROTO => ['quotes' => 5]]);

		$this->assertSame(['uri' => 'at://x', 'quotes' => 5], $this->written[Details::ATPROTO]);
	}

	/**
	 * Only the keys the counts are kept in are written, onto the blob as it is
	 * stored when the write is made: what another writer stored there after
	 * the post was read — a mention, a refused quote — stays.
	 */
	public function testWhatAnotherWriterStoredMeanwhileIsKept(): void {
		$post = new Note();
		$post->setId(self::POST);
		$post->setDetailInt(Details::REMOTE_REPLIES, 7);
		$post->setDetailArray(Details::ATPROTO, ['uri' => 'at://x']);
		$this->streams->method('getStreamById')->willReturn($post);
		$this->storing($post, [
			Details::REMOTE_REPLIES => 9,
			Details::MENTIONS => [['id' => 'https://remote.example/users/bob']],
			Details::ATPROTO => ['uri' => 'at://x', 'cid' => 'b'],
		]);

		$this->writer()->write(self::POST, 3, null, null, [Details::ATPROTO => ['quotes' => 5]]);

		$this->assertSame([['id' => 'https://remote.example/users/bob']], $this->written[Details::MENTIONS]);
		$this->assertSame(['uri' => 'at://x', 'cid' => 'b', 'quotes' => 5], $this->written[Details::ATPROTO]);
		$this->assertSame(9, $this->written[Details::REMOTE_REPLIES], 'the half as stored, not as read earlier');
		$this->assertSame(9, $post->getDetailInt(Details::REMOTE_REPLIES), 'and recounted from that');
	}

	/** A post whose details kept changing is stamped as asked and not recounted. */
	public function testABlobThatKeptChangingIsStampedAndNotRecounted(): void {
		$post = new Note();
		$post->setId(self::POST);
		$this->streams->method('getStreamById')->willReturn($post);
		$this->streams->method('changeDetails')->willReturn(null);
		$this->streams->expects($this->once())->method('markCountsRefreshed');
		$this->streams->expects($this->never())->method('recount');

		$this->assertFalse($this->writer()->write(self::POST, 1, 1, 1));
	}

	public function testAPostThatIsGoneIsNotWritten(): void {
		$this->streams->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streams->expects($this->never())->method('changeDetails');
		$this->streams->expects($this->never())->method('updateDetails');

		$this->assertFalse($this->writer()->write(self::POST, 1, 1, 1));
	}

	/**
	 * A local post on another network: its totals include part of what is
	 * held here, so only what they state beyond that part is the origin's
	 * half, and nothing counted here is subtracted that the network never saw.
	 */
	public function testALocalPostKeepsOnlyWhatTheNetworkCountsBeyondWhatIsHeldHere(): void {
		$post = new Note();
		$post->setId(self::POST);
		$this->streams->method('getStreamById')->willReturn($post);
		$this->actions->expects($this->never())->method('countActions');
		$calls = [];
		$this->storing($post, null, $calls);
		$this->streams->expects($this->once())->method('markCountsRefreshed')
			->with(self::POST, $this->callback(static fn (DateTime $at): bool => $at->getTimestamp() === self::NOW));
		$this->streams->expects($this->once())->method('recount')
			->with($this->identicalTo($post), Details::LIKES, Details::BOOSTS, Details::REPLIES, Details::QUOTES)
			->willReturnCallback(static function () use (&$calls): void {
				$calls[] = 'recount';
			});

		$this->assertTrue($this->writer()->writeBeyond(
			self::POST,
			[Details::LIKES => 7, Details::BOOSTS => 2, Details::REPLIES => 3, Details::QUOTES => 1],
			[Details::LIKES => 5, Details::BOOSTS => 2, Details::REPLIES => 1, Details::QUOTES => 0],
		));

		$this->assertSame(2, $post->getDetailInt(Details::REMOTE_LIKES), 'two likes no notification brought');
		$this->assertSame(0, $post->getDetailInt(Details::REMOTE_BOOSTS));
		$this->assertSame(2, $post->getDetailInt(Details::REMOTE_REPLIES));
		$this->assertSame(1, $post->getDetailInt(Details::REMOTE_QUOTES));
		$this->assertSame(['details', 'recount'], $calls);
	}

	public function testEverythingTheNetworkCountsHeldHereLeavesNothingBeyond(): void {
		$post = new Note();
		$post->setId(self::POST);
		$this->streams->method('getStreamById')->willReturn($post);
		$this->storing($post);

		$this->writer()->writeBeyond(
			self::POST,
			[Details::LIKES => 4, Details::BOOSTS => 1, Details::REPLIES => 0, Details::QUOTES => 0],
			[Details::LIKES => 4, Details::BOOSTS => 2, Details::REPLIES => 0, Details::QUOTES => 0],
		);

		$this->assertSame(0, $post->getDetailInt(Details::REMOTE_LIKES));
		$this->assertSame(0, $post->getDetailInt(Details::REMOTE_BOOSTS), 'never below zero: a boost not indexed there yet');
	}

	public function testACountTheNetworkDoesNotStateKeepsTheHalfStored(): void {
		$post = new Note();
		$post->setId(self::POST);
		$post->setDetailInt(Details::REMOTE_LIKES, 3);
		$post->setDetailInt(Details::BOOSTS, 6);
		$this->streams->method('getStreamById')->willReturn($post);
		$this->storing($post);

		$this->writer()->writeBeyond(self::POST, [Details::LIKES => null, Details::BOOSTS => null], []);

		$this->assertSame(3, $post->getDetailInt(Details::REMOTE_LIKES));
		$this->assertArrayNotHasKey(Details::REMOTE_BOOSTS, $this->written, 'nothing made up from the total on the row');
	}

	public function testALocalPostThatIsGoneIsNotWritten(): void {
		$this->streams->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$this->streams->expects($this->never())->method('changeDetails');
		$this->streams->expects($this->never())->method('updateDetails');

		$this->assertFalse($this->writer()->writeBeyond(self::POST, [Details::LIKES => 1], []));
	}

	public function testAStampLeavesTheCountsAlone(): void {
		$this->streams->expects($this->once())->method('markCountsRefreshed')
			->with(self::POST, $this->callback(static fn (DateTime $at): bool => $at->getTimestamp() === self::NOW));
		$this->streams->expects($this->never())->method('changeDetails');
		$this->streams->expects($this->never())->method('updateDetails');

		$this->writer()->stamp(self::POST);
	}

	/** A post its server says is gone is not due again for `GONE_FOR`. */
	public function testAPostThatIsGoneIsStampedFarAhead(): void {
		$this->streams->expects($this->once())->method('markCountsRefreshed')
			->with(self::POST, $this->callback(static fn (DateTime $at): bool => $at->getTimestamp() === self::NOW + CountWriter::GONE_FOR));
		$this->streams->expects($this->never())->method('changeDetails');

		$this->writer()->stampGone(self::POST);
	}
}
