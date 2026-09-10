<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Command;

use OCA\Social\Command\QueueRetry;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\RequestQueueService;
use OCP\Server;

/**
 * `social:queue:retry`: put a delivery that failed for a reason since fixed
 * back in the queue, or take a poisoned one out of it. Before this command an
 * administrator could see a delivery failing (`social:queue:status`) and do
 * nothing about it but wait for its fifteenth attempt to delete it.
 */
class QueueRetryTest extends CommandTestCase {
	private const AUTHOR = 'https://cloud.example.org/qretry/users/author';
	private const INBOX = 'https://remote.example/qretry/inbox';

	private RequestQueueService $service;
	private RequestQueueRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->service = Server::get(RequestQueueService::class);
		$this->request = Server::get(RequestQueueRequest::class);
		$this->request->deleteByAuthor(self::AUTHOR);
	}

	protected function tearDown(): void {
		$this->request->deleteByAuthor(self::AUTHOR);
		parent::tearDown();
	}

	public function testRetryClearsTheAttemptCountAndPutsTheRowBackOnStandby(): void {
		$token = $this->failedDelivery();
		$this->assertSame(1, $this->tries($token));

		$tester = $this->tester(QueueRetry::class);
		$code = $this->runNonInteractive($tester, ['--token' => $token, '--force' => true]);

		$this->assertSame(0, $code, $tester->getDisplay());
		$this->assertStringContainsString('queued again', $tester->getDisplay());
		$this->assertSame(0, $this->tries($token), 'the attempt count has to be cleared');
		$this->assertCount(
			1,
			$this->service->getRequestFromToken($token, RequestQueue::STATUS_STANDBY),
			'and the row has to be on standby again'
		);
	}

	public function testFlushDropsTheDelivery(): void {
		$token = $this->failedDelivery();

		$tester = $this->tester(QueueRetry::class);
		$code = $this->runNonInteractive($tester, ['--token' => $token, '--flush' => true, '--force' => true]);

		$this->assertSame(0, $code, $tester->getDisplay());
		$this->assertStringContainsString('dropped', $tester->getDisplay());
		$this->assertSame([], $this->service->getRequestFromToken($token));
	}

	public function testAnUnknownTokenTouchesNothing(): void {
		$token = $this->failedDelivery();

		$tester = $this->tester(QueueRetry::class);
		$code = $this->runNonInteractive($tester, ['--token' => 'no-such-token', '--force' => true]);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('nothing', $tester->getDisplay());
		$this->assertSame(1, $this->tries($token), 'the real delivery must be untouched');
	}

	public function testItRefusesToActNonInteractivelyWithoutForce(): void {
		$token = $this->failedDelivery();

		$tester = $this->tester(QueueRetry::class);
		$code = $this->runNonInteractive($tester, ['--token' => $token, '--flush' => true]);

		$this->assertSame(1, $code);
		$this->assertSame(1, $this->tries($token), 'nothing may be dropped without a confirmation');
	}

	public function testDecliningTheConfirmationLeavesTheQueueAlone(): void {
		$token = $this->failedDelivery();

		$tester = $this->tester(QueueRetry::class);
		$code = $this->runInteractive($tester, ['--token' => $token, '--flush' => true], ['n']);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('cancelled', $tester->getDisplay());
		$this->assertSame(1, $this->tries($token));
	}

	/** A queued delivery that has already failed once. */
	private function failedDelivery(): string {
		$note = new Note();
		$note->setId(self::AUTHOR . '/notes/' . bin2hex(random_bytes(4)));

		$token = $this->service->generateRequestQueue(
			[new InstancePath(self::INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW)],
			$note,
			self::AUTHOR
		);

		$queued = $this->service->getRequestFromToken($token);
		$this->assertCount(1, $queued);
		$this->service->initRequest($queued[0]);
		$this->service->endRequest($queued[0], false);

		return $token;
	}

	private function tries(string $token): int {
		$requests = $this->service->getRequestFromToken($token);
		$this->assertCount(1, $requests);

		return $requests[0]->getTries();
	}
}
