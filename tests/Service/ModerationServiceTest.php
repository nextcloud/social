<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\ModerationService;
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
	private ModerationService $service;

	protected function setUp(): void {
		$this->moderationRequest = $this->createMock(ModerationRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);

		$this->service = new ModerationService(
			$this->moderationRequest,
			$this->streamRequest,
			$this->cacheActorsRequest,
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
			$this->moderationRequest, $this->streamRequest, $this->cacheActorsRequest, $logger
		);

		$service->decide(self::SPAMMER, Moderation::SILENCE);
		$this->addToAssertionCount(1);
	}

	public function testRemovingAPostDeletesThatPostOnly(): void {
		$this->streamRequest->expects($this->once())->method('deleteById')->with('https://spam.example/p/1');
		$this->streamRequest->expects($this->never())->method('deleteByAuthor');

		$this->service->removeStream('https://spam.example/p/1');
	}
}
