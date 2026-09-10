<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Command;

use OCA\Social\Command\QueueStatus;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\RequestQueueService;
use OCP\Server;

/**
 * `social:queue:status` is the one diagnostic an administrator has when
 * federation stops working, so it has to answer with the state of the queue
 * when asked with no arguments — it used to insist on a --token.
 */
class QueueStatusTest extends CommandTestCase {
	private const AUTHOR = 'https://cloud.example.org/qstatus/users/author';
	private const INBOX = 'https://remote.example/qstatus/inbox';

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

	public function testWithNoArgumentsItSummarisesTheQueue(): void {
		$this->enqueue();
		$tester = $this->tester(QueueStatus::class);

		$code = $this->runNonInteractive($tester, []);
		$display = $tester->getDisplay();

		$this->assertSame(0, $code);
		$this->assertStringContainsString('deliveries waiting', $display);
	}

	public function testAFailingDeliveryIsNamedWithItsHost(): void {
		$token = $this->enqueue();
		$queued = $this->service->getRequestFromToken($token);
		$this->service->initRequest($queued[0]);
		$this->service->endRequest($queued[0], false);

		$tester = $this->tester(QueueStatus::class);
		$this->runNonInteractive($tester, []);
		$display = $tester->getDisplay();

		$this->assertStringContainsString('have failed at least once', $display);
		$this->assertStringContainsString('remote.example', $display);
	}

	public function testATokenPrintsThatDeliveryOnly(): void {
		$token = $this->enqueue();
		$tester = $this->tester(QueueStatus::class);

		$code = $this->runNonInteractive($tester, ['--token' => $token]);
		$display = $tester->getDisplay();

		$this->assertSame(0, $code);
		$this->assertStringContainsString($token, $display);
	}

	private function enqueue(): string {
		$note = new Note();
		$note->setId(self::AUTHOR . '/notes/' . bin2hex(random_bytes(4)));

		return $this->service->generateRequestQueue(
			[new InstancePath(self::INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW)],
			$note,
			self::AUTHOR
		);
	}
}
