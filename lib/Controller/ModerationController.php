<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\ReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Admin-only moderation actions behind the Social section of the admin
 * settings. Every route requires an admin session and a CSRF token — none of
 * this is part of the client API.
 */
class ModerationController extends Controller {
	public function __construct(
		IRequest $request,
		private ReportService $reportService,
		private FediverseService $fediverseService,
		private ConfigService $configService,
		private ModerationService $moderationService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Silences or suspends an account, or lifts whatever stands against it.
	 *
	 * @param string $actorId the account
	 * @param string $level 'silence', 'suspend', or '' to lift
	 * @param string $comment why, for whoever reads the list later
	 */
	public function accountModerate(string $actorId, string $level, string $comment = ''): DataResponse {
		$actorId = trim($actorId);
		if ($actorId === '') {
			return new DataResponse(['error' => 'no account given'], Http::STATUS_BAD_REQUEST);
		}

		if ($level === '') {
			$this->moderationService->lift($actorId);

			return new DataResponse(['actor_id' => $actorId, 'level' => '']);
		}

		try {
			return new DataResponse($this->moderationService->decide($actorId, $level, $comment));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Takes one post down, whoever wrote it. */
	public function statusRemove(string $streamId): DataResponse {
		$streamId = trim($streamId);
		if ($streamId === '') {
			return new DataResponse(['error' => 'no post given'], Http::STATUS_BAD_REQUEST);
		}

		$this->moderationService->removeStream($streamId);

		return new DataResponse(['stream_id' => $streamId]);
	}

	public function reportResolve(int $id, bool $resolved = true): DataResponse {
		try {
			return new DataResponse($this->reportService->setResolved($id, $resolved));
		} catch (ReportNotFoundException $e) {
			return new DataResponse(['error' => 'report not found'], Http::STATUS_NOT_FOUND);
		}
	}

	public function fediverseAdd(string $address): DataResponse {
		$address = strtolower(trim($address));
		if ($address === '' || !preg_match('/^[a-z0-9.:\[\]-]+$/', $address)) {
			return new DataResponse(['error' => 'invalid address'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->fediverseService->addAddress($address);

		return new DataResponse(['list' => $this->fediverseService->getListedAddresses()]);
	}

	public function fediverseRemove(string $address): DataResponse {
		$this->fediverseService->removeAddress(strtolower(trim($address)));

		return new DataResponse(['list' => $this->fediverseService->getListedAddresses()]);
	}

	public function retention(int $days): DataResponse {
		if ($days < 0 || $days > 3650) {
			return new DataResponse(['error' => 'invalid retention period'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_RETENTION_DAYS, (string)$days);

		return new DataResponse(['retentionDays' => $days]);
	}

	public function fediverseAccess(string $type): DataResponse {
		try {
			$this->fediverseService->setAccessType($type);
		} catch (Exception $e) {
			return new DataResponse(['error' => 'invalid access type'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['accessType' => $this->fediverseService->getAccessType()]);
	}
}
