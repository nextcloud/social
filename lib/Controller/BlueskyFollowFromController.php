<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Move\FollowFrom;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ImportJob;
use OCA\Social\Service\ImportQueueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Following from here the people a Bluesky account follows, while the
 * Bluesky account stays where it is (FollowFrom).
 */
class BlueskyFollowFromController extends Controller {
	public function __construct(
		IRequest $request,
		private ?string $userId,
		private FollowFrom $followFrom,
		private ImportQueueService $imports,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `POST /api/v1/social/bluesky/follow-from`: the account, by handle or
	 * DID, is looked up now, and its follows are followed from here in the
	 * background; `GET /api/v1/migration/imports` is where that gets to.
	 * Each start reads another server, so a few an hour.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/follow-from')]
	public function followFrom(string $account = ''): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in', 'code' => 'not-signed-in'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			if ($this->imports->hasActive($this->userId, ImportJob::KIND_BLUESKY_FOLLOWS)) {
				throw new MoveErrorException('These follows are being taken over already', 'import-in-progress');
			}
			$source = $this->followFrom->resolve($account);
			$job = $this->imports->queue($this->userId, ImportJob::KIND_BLUESKY_FOLLOWS, null, $source);

			return new DataResponse(['import' => $job, 'account' => $source], Http::STATUS_ACCEPTED);
		} catch (MoveErrorException $e) {
			return new DataResponse($e->toArray(), $e->errorCode === 'bluesky-off' ? Http::STATUS_NOT_FOUND : Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->warning('Bluesky follows not queued', ['exception' => $e]);

			return new DataResponse(['error' => $e->getMessage(), 'code' => 'server-error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
