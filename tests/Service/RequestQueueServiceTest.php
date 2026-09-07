<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RequestQueueServiceTest extends TestCase {
	private const AUTHOR = 'https://cloud.example.com/apps/social/@alice';

	private RequestQueueRequest|MockObject $requestQueueRequest;
	private RequestQueueService $service;

	protected function setUp(): void {
		$this->requestQueueRequest = $this->createMock(RequestQueueRequest::class);
		$this->service = new RequestQueueService(
			$this->requestQueueRequest,
			$this->createMock(ConfigService::class),
			$this->createMock(MiscService::class),
		);
	}

	private function queued(int $priority, int $status = RequestQueue::STATUS_STANDBY): RequestQueue {
		$queue = new RequestQueue('{}', new InstancePath('https://remote.example/inbox', InstancePath::TYPE_INBOX, $priority), self::AUTHOR);
		$queue->setStatus($status);

		return $queue;
	}

	public function testGenerateRequestQueueCreatesOneEntryPerInstanceSharingAToken(): void {
		$note = new Note();
		$note->setId('https://cloud.example.com/apps/social/@alice/1');
		$note->setContent('hello');
		$inbox = new InstancePath('https://remote.example/users/bob/inbox', InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH);
		$shared = new InstancePath('https://other.example/inbox', InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_LOW);
		$stored = [];
		$this->requestQueueRequest->expects($this->once())
			->method('multiple')
			->willReturnCallback(function (array $requests) use (&$stored) {
				$stored = $requests;
			});

		$token = $this->service->generateRequestQueue([$inbox, $shared], $note, self::AUTHOR);

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $token);
		$this->assertCount(2, $stored);
		foreach ($stored as $request) {
			$this->assertInstanceOf(RequestQueue::class, $request);
			$this->assertSame($token, $request->getToken());
			$this->assertSame(self::AUTHOR, $request->getAuthor());
			$this->assertSame(json_encode($note, JSON_UNESCAPED_SLASHES), $request->getActivity());
			$this->assertSame(RequestQueue::STATUS_STANDBY, $request->getStatus());
		}
		$this->assertSame($inbox, $stored[0]->getInstance());
		$this->assertSame(InstancePath::PRIORITY_HIGH, $stored[0]->getPriority());
		$this->assertSame($shared, $stored[1]->getInstance());
		$this->assertSame(InstancePath::PRIORITY_LOW, $stored[1]->getPriority());
	}

	public function testGenerateRequestQueueWithoutInstancesStoresNothingAndHasNoToken(): void {
		$this->requestQueueRequest->expects($this->once())->method('multiple')->with([]);

		$this->assertSame('', $this->service->generateRequestQueue([], new Note(), self::AUTHOR));
	}

	public function testGetPriorityRequestOnEmptyQueueThrows(): void {
		$this->requestQueueRequest->method('getFromToken')->with('tok')->willReturn([]);

		$this->expectException(EmptyQueueException::class);
		$this->service->getPriorityRequest('tok');
	}

	public function testTopPriorityIsAlwaysRunInline(): void {
		$top = $this->queued(InstancePath::PRIORITY_TOP);
		$this->requestQueueRequest->method('getFromToken')
			->willReturn([$top, $this->queued(InstancePath::PRIORITY_TOP)]);

		$this->assertSame($top, $this->service->getPriorityRequest('tok'));
	}

	public function testSingleHighPriorityRequestIsRunInline(): void {
		$high = $this->queued(InstancePath::PRIORITY_HIGH);
		$this->requestQueueRequest->method('getFromToken')->willReturn([$high]);

		$this->assertSame($high, $this->service->getPriorityRequest('tok'));
	}

	public function testHighPriorityFollowedByAStandbyRequestIsRunInline(): void {
		$high = $this->queued(InstancePath::PRIORITY_HIGH);
		$this->requestQueueRequest->method('getFromToken')
			->willReturn([$high, $this->queued(InstancePath::PRIORITY_LOW, RequestQueue::STATUS_STANDBY)]);

		$this->assertSame($high, $this->service->getPriorityRequest('tok'));
	}

	public function testSingleMediumPriorityRequestIsRunInline(): void {
		$medium = $this->queued(InstancePath::PRIORITY_MEDIUM);
		$this->requestQueueRequest->method('getFromToken')->willReturn([$medium]);

		$this->assertSame($medium, $this->service->getPriorityRequest('tok'));
	}

	public function testSeveralMediumPriorityRequestsAreDeferred(): void {
		$this->requestQueueRequest->method('getFromToken')
			->willReturn([$this->queued(InstancePath::PRIORITY_MEDIUM), $this->queued(InstancePath::PRIORITY_MEDIUM)]);

		$this->expectException(NoHighPriorityRequestException::class);
		$this->service->getPriorityRequest('tok');
	}

	public function testLowPriorityIsAlwaysDeferred(): void {
		$this->requestQueueRequest->method('getFromToken')->willReturn([$this->queued(InstancePath::PRIORITY_LOW)]);

		$this->expectException(NoHighPriorityRequestException::class);
		$this->service->getPriorityRequest('tok');
	}

	public function testGetRequestStandbyAppliesTheRetryBackoff(): void {
		$now = time();
		$fresh = $this->queued(InstancePath::PRIORITY_LOW)->setTries(0)->setLast($now - 1);
		$retriedRecently = $this->queued(InstancePath::PRIORITY_LOW)->setTries(3)->setLast($now - 10); // delay 27s
		$retriedLongAgo = $this->queued(InstancePath::PRIORITY_LOW)->setTries(3)->setLast($now - 60);
		$this->requestQueueRequest->method('getStandby')->willReturn([$fresh, $retriedRecently, $retriedLongAgo]);

		$total = 0;
		$ready = $this->service->getRequestStandby($total);

		$this->assertSame(3, $total);
		$this->assertSame([$fresh, $retriedLongAgo], $ready);
	}

	public function testGetRequestFromTokenSkipsTheDatabaseForAnEmptyToken(): void {
		$this->requestQueueRequest->expects($this->never())->method('getFromToken');

		$this->assertSame([], $this->service->getRequestFromToken(''));
	}

	public function testGetRequestFromTokenForwardsTheStatusFilter(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		$this->requestQueueRequest->expects($this->once())
			->method('getFromToken')
			->with('tok', RequestQueue::STATUS_STANDBY)
			->willReturn([$queue]);

		$this->assertSame([$queue], $this->service->getRequestFromToken('tok', RequestQueue::STATUS_STANDBY));
	}

	public function testInitRequestMarksTheQueueRunning(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		$this->requestQueueRequest->expects($this->once())->method('setAsRunning')->with($this->identicalTo($queue));

		$this->service->initRequest($queue);
	}

	public function testInitRequestPropagatesAStatusConflict(): void {
		$this->requestQueueRequest->method('setAsRunning')->willThrowException(new QueueStatusException());

		$this->expectException(QueueStatusException::class);
		$this->service->initRequest($this->queued(InstancePath::PRIORITY_LOW));
	}

	public function testEndRequestOnSuccess(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		$this->requestQueueRequest->expects($this->once())->method('setAsSuccess')->with($this->identicalTo($queue));
		$this->requestQueueRequest->expects($this->never())->method('setAsFailure');

		$this->service->endRequest($queue, true);
	}

	public function testEndRequestOnFailure(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		$this->requestQueueRequest->expects($this->once())->method('setAsFailure')->with($this->identicalTo($queue));
		$this->requestQueueRequest->expects($this->never())->method('setAsSuccess');

		$this->service->endRequest($queue, false);
	}

	public function testEndRequestSwallowsStatusConflicts(): void {
		$this->requestQueueRequest->method('setAsSuccess')->willThrowException(new QueueStatusException());

		$this->service->endRequest($this->queued(InstancePath::PRIORITY_LOW), true);
		$this->addToAssertionCount(1);
	}

	public function testDeleteRequestDelegates(): void {
		$queue = $this->queued(InstancePath::PRIORITY_LOW);
		$this->requestQueueRequest->expects($this->once())->method('delete')->with($this->identicalTo($queue));

		$this->service->deleteRequest($queue);
	}
}
