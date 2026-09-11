<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The two decisions a moderator can make, and what each one costs.
 *
 * Silencing changes no data and is undone by lifting. Suspending deletes, so
 * the tests below pin exactly what it deletes and what lifting does not
 * restore — an administrator has to be able to trust the difference.
 */
class ModerationServiceTest extends TestCase {
	private const SPAMMER = 'https://spam.example/users/spammer';

	private ModerationRequest|MockObject $moderationRequest;
	private StreamRequest|MockObject $streamRequest;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private FollowsRequest|MockObject $followsRequest;
	private ActorRelationRequest|MockObject $actorRelationRequest;
	private StreamDestRequest|MockObject $streamDestRequest;
	private RequestQueueRequest|MockObject $requestQueueRequest;
	private StreamService|MockObject $streamService;
	private ModerationService $service;

	protected function setUp(): void {
		$this->moderationRequest = $this->createMock(ModerationRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->actorRelationRequest = $this->createMock(ActorRelationRequest::class);
		$this->streamDestRequest = $this->createMock(StreamDestRequest::class);
		$this->requestQueueRequest = $this->createMock(RequestQueueRequest::class);
		$this->streamService = $this->createMock(StreamService::class);

		$this->service = new ModerationService(
			$this->moderationRequest,
			$this->streamRequest,
			$this->cacheActorsRequest,
			$this->followsRequest,
			$this->actorRelationRequest,
			$this->streamDestRequest,
			$this->requestQueueRequest,
			$this->streamService,
			new NullLogger(),
		);
	}

	public function testSilencingRecordsTheDecisionAndDeletesNothing(): void {
		$saved = null;
		$this->moderationRequest->expects($this->once())->method('save')
			->willReturnCallback(function (Moderation $m) use (&$saved): void {
				$saved = $m;
			});

		// the account keeps its followers; only the public square is closed
		$this->streamRequest->expects($this->never())->method('deleteByAuthor');
		$this->cacheActorsRequest->expects($this->never())->method('deleteCacheById');
		$this->followsRequest->expects($this->never())->method('deleteRelatedId');
		$this->streamDestRequest->expects($this->never())->method('deleteRelatedToActor');

		$this->service->decide(self::SPAMMER, Moderation::SILENCE, 'endless crypto');

		$this->assertSame(self::SPAMMER, $saved->getActorId());
		$this->assertSame(Moderation::SILENCE, $saved->getLevel());
		$this->assertSame('endless crypto', $saved->getComment());
	}

	public function testSuspendingRemovesWhatTheAccountPostedHere(): void {
		$this->moderationRequest->expects($this->once())->method('save');
		$this->streamRequest->expects($this->once())->method('deleteByAuthor')->with(self::SPAMMER);
		$this->cacheActorsRequest->expects($this->once())->method('deleteCacheById')->with(self::SPAMMER);

		$this->service->decide(self::SPAMMER, Moderation::SUSPEND);
	}

	public function testSuspendingCutsTheAccountOutOfDeliveryAndOfTimelines(): void {
		// a suspension that left these behind kept delivering every local post
		// to the account, and kept its posts addressed into local timelines
		$this->followsRequest->expects($this->once())->method('deleteRelatedId')->with(self::SPAMMER);
		$this->actorRelationRequest->expects($this->once())->method('deleteRelatedId')->with(self::SPAMMER);
		$this->streamDestRequest->expects($this->once())->method('deleteRelatedToActor')->with(self::SPAMMER);
		$this->requestQueueRequest->expects($this->once())->method('deleteByAuthor')->with(self::SPAMMER);

		$this->service->decide(self::SPAMMER, Moderation::SUSPEND);
	}

	public function testOneFailureWhileDetachingDoesNotStopTheRest(): void {
		$this->followsRequest->method('deleteRelatedId')
			->willThrowException(new \RuntimeException('database busy'));

		// the rest of the purge still runs, and the decision still stands
		$this->streamDestRequest->expects($this->once())->method('deleteRelatedToActor')->with(self::SPAMMER);
		$this->requestQueueRequest->expects($this->once())->method('deleteByAuthor')->with(self::SPAMMER);
		$this->cacheActorsRequest->expects($this->once())->method('deleteCacheById')->with(self::SPAMMER);

		$this->service->decide(self::SPAMMER, Moderation::SUSPEND);
	}

	public function testSuspensionSurvivesAFailureToPurge(): void {
		$this->moderationRequest->expects($this->once())->method('save');
		$this->streamRequest->method('deleteByAuthor')
			->willThrowException(new \RuntimeException('database busy'));

		// the decision is recorded either way, so the account stays refused
		// even if this instance could not finish tidying up after it
		$this->service->decide(self::SPAMMER, Moderation::SUSPEND);
	}

	public function testALocalAccountWithNoCachedCopyIsNotAFailure(): void {
		$this->cacheActorsRequest->method('deleteCacheById')
			->willThrowException(new \RuntimeException('nothing there'));

		$this->service->decide(self::SPAMMER, Moderation::SUSPEND);
		$this->addToAssertionCount(1);
	}

	public function testAnUnknownDecisionIsRefused(): void {
		$this->moderationRequest->expects($this->never())->method('save');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->decide(self::SPAMMER, 'banish');
	}

	public function testLiftingForgetsTheDecisionWithoutRestoringAnything(): void {
		$this->moderationRequest->expects($this->once())->method('delete')->with(self::SPAMMER);
		// nothing can bring back what a suspension deleted, and nothing pretends to
		$this->streamRequest->expects($this->never())->method('save');

		$this->service->lift(self::SPAMMER);
	}

	public function testSuspensionIsReportedSoTheInboxCanRefuseIt(): void {
		$this->moderationRequest->method('levelOf')->willReturnMap([
			[self::SPAMMER, Moderation::SUSPEND],
			['https://good.example/users/bob', ''],
		]);

		$this->assertTrue($this->service->isSuspended(self::SPAMMER));
		$this->assertFalse($this->service->isSuspended('https://good.example/users/bob'));
	}

	public function testASuspendedAccountIsRefusedWhatItWouldSend(): void {
		$this->moderationRequest->method('levelOf')->willReturn(Moderation::SUSPEND);

		$this->expectException(InvalidActionException::class);

		$this->service->assertNotSuspended(self::SPAMMER);
	}

	public function testASilencedAccountMayStillPost(): void {
		// silencing closes the public timelines, it does not gag the account
		$this->moderationRequest->method('levelOf')->willReturn(Moderation::SILENCE);

		$this->service->assertNotSuspended(self::SPAMMER);
		$this->addToAssertionCount(1);
	}

	public function testASilencedAccountIsNotSuspended(): void {
		$this->moderationRequest->method('levelOf')->willReturn(Moderation::SILENCE);

		// silencing must never stop an account being delivered to its followers
		$this->assertFalse($this->service->isSuspended(self::SPAMMER));
	}

	public function testNothingIsLoggedUnderAKeyTheServerReservesForItself(): void {
		// the server's logger reads a string `level` in a log context as a log
		// level and throws on anything else, so 'silence' there took the whole
		// request down. Found by running it; this keeps it found.
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message, array $context): void {
			if (isset($context['level']) && is_string($context['level'])) {
				throw new \Psr\Log\InvalidArgumentException('Unsupported custom log level');
			}
		});

		$service = new ModerationService(
			$this->moderationRequest, $this->streamRequest, $this->cacheActorsRequest,
			$this->followsRequest, $this->actorRelationRequest, $this->streamDestRequest,
			$this->requestQueueRequest, $this->createMock(StreamService::class), $logger
		);

		$service->decide(self::SPAMMER, Moderation::SILENCE);
		$this->addToAssertionCount(1);
	}

	private function post(string $id, bool $local): Note {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo(self::SPAMMER);
		$note->setLocal($local);
		$this->streamRequest->method('getStreamById')->with($id)->willReturn($note);

		return $note;
	}

	public function testRemovingARemotePostDeletesThatPostAndOnlyThatPost(): void {
		// deleteById() removes the post and everything keyed to it; what it
		// must not do is touch anything else the author wrote
		$this->post('https://spam.example/p/1', false);
		$this->streamRequest->expects($this->once())->method('deleteById')->with('https://spam.example/p/1');
		$this->streamRequest->expects($this->never())->method('deleteByAuthor');

		$this->service->removeStream('https://spam.example/p/1');
	}

	public function testRemovingARemotePostFederatesNothing(): void {
		// this instance is not the origin of somebody else's post and has no
		// standing to tell the rest of the fediverse it is gone
		$this->post('https://spam.example/p/1', false);
		$this->streamService->expects($this->never())->method('deleteLocalItem');

		$this->service->removeStream('https://spam.example/p/1');
	}

	public function testRemovingALocalPostFederatesTheDelete(): void {
		// a row dropped here alone leaves the post live on every instance that
		// holds a copy of it
		$note = $this->post('https://cloud.example/@bob/1', true);
		$this->streamService->expects($this->once())
			->method('deleteLocalItem')
			->with($this->identicalTo($note));
		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->service->removeStream('https://cloud.example/@bob/1');
	}

	public function testATakedownDoesNotWaitOnTheFediverse(): void {
		$this->post('https://cloud.example/@bob/1', true);
		$this->streamService->method('deleteLocalItem')
			->willThrowException(new \RuntimeException('no route to host'));

		// the post goes either way; the Delete is what is lost
		$this->streamRequest->expects($this->once())
			->method('deleteById')->with('https://cloud.example/@bob/1');

		$this->service->removeStream('https://cloud.example/@bob/1');
	}

	public function testRemovingAPostThatIsNotThereIsNotAFailure(): void {
		$this->streamRequest->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->streamRequest->expects($this->never())->method('deleteById');

		$this->service->removeStream('https://spam.example/p/gone');
		$this->addToAssertionCount(1);
	}
}
