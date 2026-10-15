<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\ApproveReplyInterface;
use OCA\Social\Model\ActivityPub\Activity\ApproveReply;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Service\SignatureService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * "Your reply may be shown": FEP-5624, as PeerTube ≥ 6.2 sends it.
 *
 * All it may do is move a state on a reply of ours. What is held still is who
 * is allowed to say it — anybody else would be a stranger approving a reply on
 * somebody else's post.
 */
#[AllowMockObjectsWithoutExpectations]
class ApproveReplyInterfaceTest extends TestCase {
	private const REPLY = 'https://cloud.example/apps/social/@alice/9';
	private const VIDEO = 'https://peertube.example/videos/watch/6f4c1e1a';
	private const CHANNEL = 'https://peertube.example/video-channels/bob_channel';
	private const OWNER = 'https://peertube.example/accounts/bob';

	private StreamRequest|MockObject $streamRequest;
	private ApproveReplyInterface $handler;

	protected function setUp(): void {
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->handler = new ApproveReplyInterface($this->streamRequest, new NullLogger());
	}

	private function reply(bool $local = true, string $inReplyTo = self::VIDEO): Note {
		$note = new Note();
		$note->setId(self::REPLY)->setLocal($local)->setInReplyTo($inReplyTo);
		$note->setReplyState(Stream::REPLY_PENDING);

		return $note;
	}

	/**
	 * The video, stored with the channel as its author and its own document,
	 * which names the channel and the account behind it, as PeerTube sends it.
	 */
	private function video(): Note {
		$video = new Note();
		$video->setId(self::VIDEO)->setAttributedTo(self::CHANNEL);
		$video->setSource((string)json_encode([
			'id' => self::VIDEO,
			'type' => 'Video',
			'attributedTo' => [
				['type' => 'Person', 'id' => self::OWNER],
				['type' => 'Group', 'id' => self::CHANNEL],
			],
		]));

		return $video;
	}

	/** The reply and the video, as the store holds them. */
	private function stored(?Note $reply = null, ?Note $video = null): Note {
		$reply ??= $this->reply();
		$video ??= $this->video();
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(static fn (string $id): Stream => match ($id) {
				self::REPLY => $reply,
				self::VIDEO => $video,
				default => throw new StreamNotFoundException($id),
			});

		return $reply;
	}

	private function approval(
		string $origin = 'peertube.example', string $objectId = self::REPLY, string $actor = self::OWNER,
	): ApproveReply {
		$activity = new ApproveReply();
		$activity->setId('https://peertube.example/approvals/1');
		$activity->setActorId($actor);
		$activity->setObjectId($objectId);
		$activity->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());

		return $activity;
	}

	/** PeerTube sends the approval as the account the video's channel belongs to. */
	public function testAnApprovalMovesOurReplyToApproved(): void {
		$reply = $this->stored();
		$this->streamRequest->expects($this->once())->method('updateDetails')->with($reply, Details::REPLY_STATE);

		$this->handler->processIncomingRequest($this->approval());

		$this->assertSame(Stream::REPLY_APPROVED, $reply->getReplyState());
	}

	public function testAnApprovalFromTheStoredAuthorOfThePostIsAccepted(): void {
		$reply = $this->stored();

		$this->handler->processIncomingRequest($this->approval(actor: self::CHANNEL));

		$this->assertSame(Stream::REPLY_APPROVED, $reply->getReplyState());
	}

	/**
	 * Only the author of the post being replied to approves replies to it:
	 * another account on the same server is refused, and the reply stays
	 * pending.
	 */
	public function testAnApprovalFromAnotherAccountOnTheSameServerIsRefused(): void {
		$reply = $this->stored();
		$this->streamRequest->expects($this->never())->method('updateDetails');

		try {
			$this->handler->processIncomingRequest($this->approval(actor: 'https://peertube.example/accounts/carol'));
			$this->fail('an approval from somebody other than the author was accepted');
		} catch (InvalidOriginException) {
		}

		$this->assertSame(Stream::REPLY_PENDING, $reply->getReplyState());
	}

	/** Without the post being replied to, nobody can be told to be its author. */
	public function testAnApprovalAboutAReplyToAPostThatIsNotHereIsIgnored(): void {
		$reply = $this->reply(inReplyTo: 'https://peertube.example/videos/watch/gone');
		$this->stored($reply);
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->processIncomingRequest($this->approval());

		$this->assertSame(Stream::REPLY_PENDING, $reply->getReplyState());
	}

	public function testAnApprovalFromSomewhereElseIsRefused(): void {
		$this->stored();

		$this->expectException(InvalidOriginException::class);

		$this->handler->processIncomingRequest($this->approval(origin: 'stranger.example'));
	}

	public function testAnApprovalAboutSomethingWeNeverWroteIsIgnored(): void {
		$this->streamRequest->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->processIncomingRequest($this->approval());
	}

	/** A remote post is somebody else's document; its state is not ours to move. */
	public function testAnApprovalAboutARemotePostIsIgnored(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->reply(local: false));
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->processIncomingRequest($this->approval());
	}

	public function testAnApprovalAboutAPostThatIsNotAReplyIsIgnored(): void {
		$this->streamRequest->method('getStreamById')->willReturn($this->reply(inReplyTo: ''));
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->processIncomingRequest($this->approval());
	}

	public function testAnApprovalNamingNothingIsIgnored(): void {
		$this->streamRequest->expects($this->never())->method('getStreamById');

		$this->handler->processIncomingRequest($this->approval(objectId: ''));
	}
}
