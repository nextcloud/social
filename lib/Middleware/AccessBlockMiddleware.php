<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use OCA\Social\Service\AccessBlockService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Turns away everything an IP block at `no_access` covers.
 *
 * Here rather than at the two or three routes somebody would remember: "no
 * access" is a statement about the whole app, and a block that held on the
 * inbox but not on the API — or on last month's routes but not on the ones
 * added since — is not the thing an admin thought they were switching on.
 * Every request this app serves passes through one middleware; this is it.
 *
 * **403, not 429 or 404.** The address is being refused deliberately and
 * permanently, and telling it so is what makes a peer stop redelivering rather
 * than queue for days — the same reasoning as the refused inbox delivery in
 * `docs/Architecture.md`.
 *
 * The check costs one query per request on an instance that has any blocks,
 * and nothing at all on one that has none: the list is loaded once and then
 * lives for the length of the request.
 */
class AccessBlockMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
		private AccessBlockService $accessBlockService,
		private LoggerInterface $logger,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		$ip = $this->request->getRemoteAddress();
		if ($ip === '' || !$this->accessBlockService->isBlockedIp($ip)) {
			return;
		}

		$this->logger->info('a blocked address was refused', [
			'ip' => $ip, 'controller' => $controller::class, 'method' => $methodName,
		]);

		throw new AccessBlockedException();
	}

	public function afterException(
		Controller $controller, string $methodName, \Exception $exception,
	): Response {
		if (!($exception instanceof AccessBlockedException)) {
			throw $exception;
		}

		return new JSONResponse(
			['error' => 'This server does not accept requests from your address.'],
			Http::STATUS_FORBIDDEN
		);
	}
}
