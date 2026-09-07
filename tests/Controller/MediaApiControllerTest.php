<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\MediaApiController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class MediaApiControllerTest extends TestCase {
	public function testUploadMediaReturnsAPlaceholderAttachment(): void {
		$controller = new MediaApiController('social', $this->createMock(IRequest::class));

		$response = $controller->uploadMedia();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['id' => 1, 'url' => '', 'preview_url' => '', 'remote_url' => null, 'description' => ''],
			$response->getData()
		);
	}

	public function testImageAllowlistCoversCommonWebFormatsOnly(): void {
		foreach (['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'] as $mime) {
			$this->assertContains($mime, MediaApiController::IMAGE_MIME_TYPES);
		}
		foreach (['text/html', 'application/pdf', 'video/mp4', 'image/tiff'] as $mime) {
			$this->assertNotContains($mime, MediaApiController::IMAGE_MIME_TYPES);
		}
	}
}
