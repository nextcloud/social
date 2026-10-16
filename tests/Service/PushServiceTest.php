<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\StreamDetails;
use OCA\Social\Service\DetailsService;
use OCA\Social\Service\PushService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A PushService whose notify_push queue is a local spy: what production
 * resolves from the notify_push app when installed.
 */
class TestablePushService extends PushService {
	public ?object $testQueue = null;

	protected function getQueue(): ?object {
		return $this->testQueue;
	}
}

#[AllowMockObjectsWithoutExpectations]
class PushServiceTest extends TestCase {
	private DetailsService|MockObject $detailsService;
	private StreamService|MockObject $streamService;
	private ActorsRequest|Stub $actorsRequest;
	private TestablePushService $service;
	/** @var array<int, array{string, array}> */
	private array $pushed = [];

	protected function setUp(): void {
		$this->detailsService = $this->createMock(DetailsService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->actorsRequest = $this->createStub(ActorsRequest::class);
		$this->actorsRequest->method('getFromId')->willReturnCallback(
			function (string $id): Person {
				foreach ([$this->person('alice'), $this->person('', false)] as $person) {
					if ($person->getId() === $id) {
						return $person;
					}
				}

				throw new ActorDoesNotExistException('Actor not found');
			}
		);
		$this->service = new TestablePushService(
			$this->detailsService, $this->streamService, $this->actorsRequest, new NullLogger()
		);

		$pushed = &$this->pushed;
		$this->service->testQueue = new class($pushed) {
			/** @param array<int, array{string, array}> $pushed */
			public function __construct(
				private array &$pushed,
			) {
			}

			public function push(string $channel, array $payload): void {
				$this->pushed[] = [$channel, $payload];
			}
		};
	}

	private function person(string $userId, bool $local = true): Person {
		$person = new Person();
		$person->setId('https://cloud.example/@' . ($userId ?: 'remote'));
		$person->setUserId($userId);
		$person->setLocal($local);

		return $person;
	}

	private function withDetails(array $home, array $direct = []): void {
		$details = new StreamDetails(new Note());
		foreach ($home as $viewer) {
			$details->addHomeViewer($viewer);
		}
		foreach ($direct as $viewer) {
			$details->addDirectViewer($viewer);
		}
		$this->streamService->method('getStreamById')->willReturn(new Note());
		$this->detailsService->method('generateDetailsFromStream')->willReturn($details);
	}

	public function testEveryLocalViewerIsNotifiedOnce(): void {
		$alice = $this->person('alice');
		$this->withDetails([$alice, $this->person('bob')], [$alice, $this->person('', false)]);

		$this->service->onNewStream('https://remote.example/notes/1');

		$this->assertSame([
			['notify_custom', ['user' => 'alice', 'message' => 'social_timeline', 'body' => ['kinds' => ['home', 'direct']]]],
			['notify_custom', ['user' => 'bob', 'message' => 'social_timeline', 'body' => ['kinds' => ['home']]]],
		], $this->pushed, 'alice once despite home+direct, the remote viewer never');
	}

	/** A page that shows direct messages only hears of the posts that are some. */
	public function testAPushSaysWhatArrived(): void {
		$this->withDetails([], [$this->person('carol')]);

		$this->service->onNewStream('https://remote.example/notes/1');

		$this->assertSame(
			[['notify_custom', ['user' => 'carol', 'message' => 'social_timeline', 'body' => ['kinds' => ['direct']]]]],
			$this->pushed
		);
	}

	public function testANotificationIsPushedToItsLocalRecipient(): void {
		$this->service->onNotification('https://cloud.example/@alice');

		$this->assertSame(
			[['notify_custom', ['user' => 'alice', 'message' => 'social_timeline', 'body' => ['kinds' => ['notification']]]]],
			$this->pushed
		);
	}

	public function testANotificationForNobodyHereIsPushedNowhere(): void {
		$this->service->onNotification('https://cloud.example/@remote');
		$this->service->onNotification('https://elsewhere.example/users/dave');

		$this->assertSame([], $this->pushed);
	}

	public function testWithoutNotifyPushANotificationLooksNobodyUp(): void {
		$this->service->testQueue = null;
		$actorsRequest = $this->createMock(ActorsRequest::class);
		$actorsRequest->expects($this->never())->method('getFromId');
		$service = new TestablePushService($this->detailsService, $this->streamService, $actorsRequest, new NullLogger());

		$service->onNotification('https://cloud.example/@alice');

		$this->assertSame([], $this->pushed);
	}

	public function testWithoutNotifyPushNothingHappens(): void {
		$this->service->testQueue = null;
		$this->streamService->expects($this->never())->method('getStreamById');

		$this->service->onNewStream('https://remote.example/notes/1');

		$this->assertSame([], $this->pushed);
	}

	public function testAMissingStreamIsANoOp(): void {
		$this->streamService->method('getStreamById')
			->willThrowException(new StreamNotFoundException());
		$this->detailsService->expects($this->never())->method('generateDetailsFromStream');

		$this->service->onNewStream('gone');

		$this->assertSame([], $this->pushed);
	}
}
