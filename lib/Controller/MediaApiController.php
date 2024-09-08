<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IMimeTypeDetector;

class MediaApiController extends Controller {
	public const IMAGE_MIME_TYPES = [
		'image/png',
		'image/jpeg',
		'image/jpg',
		'image/gif',
		'image/x-xbitmap',
		'image/x-ms-bmp',
		'image/bmp',
		'image/svg+xml',
		'image/webp',
	];

	private IMimeTypeDetector $mimeTypeDetector;

	/**
	 * Creates an attachment to be used with a new status.
	 *
	 * @NoAdminRequired
	 */
	public function uploadMedia(): DataResponse {
		// TODO
		return new DataResponse([
			'id' => 1,
			'url' => '',
			'preview_url' => '',
			'remote_url' => null,
			'description' => '',
		]);
	}
}
