<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\QueueController;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QueueControllerTest extends TestCase {
	private RequestQueueService|MockObject $requestQueueService;
	private ActivityService|MockObject $activityService;
	private QueueController $controller;

	protected function setUp(): void {
		$this->requestQueueService = $this->createMock(RequestQueueService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->controller = new QueueController(
			$this->createMock(IRequest::class),
			$this->requestQueueService,
			$this->activityService,
			$this->createMock(MiscService::class)
		);
	}

	public function testAnUnknownTokenGetsARealResponseInsteadOfExit(): void {
		$this->requestQueueService->method('getRequestFromToken')
			->with('tok', RequestQueue::STATUS_STANDBY)
			->willReturn([]);
		$this->activityService->expects($this->never())->method('manageInit');

		$response = $this->controller->asyncForRequest('tok');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTheDeliveryPathCannotBeUnitTested(): void {
		$this->markTestSkipped(
			'With queued requests, asyncForRequest() detaches the HTTP response (TAsync::async()) and ends in '
			. 'exit() because the connection is already closed; that path needs an end-to-end test.'
		);
	}
}
