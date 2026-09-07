<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\QueueController;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class QueueControllerTest extends TestCase {
	public function testAsyncForRequestCannotBeUnitTested(): void {
		// The controller can at least be wired up with its dependencies.
		new QueueController(
			$this->createMock(IRequest::class),
			$this->createMock(RequestQueueService::class),
			$this->createMock(ActivityService::class),
			$this->createMock(MiscService::class)
		);

		$this->markTestSkipped(
			'QueueController::asyncForRequest() unconditionally calls exit() after detaching the HTTP '
			. 'response (TAsync::async()), which terminates the PHPUnit process; it needs an end-to-end test.'
		);
	}
}
