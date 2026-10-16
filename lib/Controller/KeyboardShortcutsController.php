<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether the web app's one-key shortcuts are on. On unless turned off; the
 * page reads it from `serverData.keyboardShortcuts`.
 */
class KeyboardShortcutsController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private ConfigService $configService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/** Turns the switch, and answers with it. `enabled` is required. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/social/keyboard_shortcuts')]
	public function update(mixed $enabled = null): DataResponse {
		try {
			$this->initViewer(['write:accounts', 'write']);

			$flag = (is_scalar($enabled) && $enabled !== '')
				? filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
			if ($flag === null) {
				throw new InvalidResourceException('enabled must be true or false');
			}

			$this->configService->setValueForUser($this->viewer->getUserId(), ConfigService::USER_KEYBOARD_SHORTCUTS, $flag ? '1' : '0');

			return new DataResponse(['enabled' => $flag], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
