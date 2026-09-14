<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\ServerSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * The Server card of the Social settings page.
 *
 * Administrators only, and not by accident: this controller carries none of
 * the relaxing attributes, so the server dispatches it for an administrator
 * with a session and a CSRF token and for nobody else. In particular a group
 * the Social section was delegated to — a moderator — passes
 * `ModerationController` and not this. Whether a peer's unsigned fetches are
 * answered, or how large an upload may be, is a decision about the server,
 * not about a report.
 */
class ServerSettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private ServerSettingsService $serverSettingsService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Writes every setting on the card, or none of them.
	 *
	 * @param string $contactEmail empty, or an address
	 * @param int $maxSize megabytes an attachment may have
	 * @param int $maxVideoSize megabytes a video may have
	 * @param int $inboxThrottle inbox requests per origin host per minute; 0 disables
	 * @param bool $secureMode refuse ActivityPub fetches that are not signed
	 * @param bool $publishBlocks publish the domain deny list on the instance API
	 * @param bool $allowSelfSigned accept peers with certificates that do not check out
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/server')]
	public function save(
		string $contactEmail = '',
		string $extendedDescription = '',
		int $maxSize = 10,
		int $maxVideoSize = 2048,
		int $inboxThrottle = 300,
		bool $secureMode = false,
		bool $publishBlocks = false,
		bool $allowSelfSigned = false,
	): DataResponse {
		try {
			return new DataResponse($this->serverSettingsService->save(
				$contactEmail,
				$extendedDescription,
				$maxSize,
				$maxVideoSize,
				$inboxThrottle,
				$secureMode,
				$publishBlocks,
				$allowSelfSigned,
			));
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}
}
