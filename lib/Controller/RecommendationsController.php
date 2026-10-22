<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\RecommendationPreferenceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/** Explicit topic preferences for the authenticated reader only. */
class RecommendationsController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private RecommendationPreferenceService $recommendationService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/recommendations/catalog')]
	public function catalog(): DataResponse {
		return $this->answer(['read:accounts'], fn (): array => ['version' => 1, 'topics' => $this->recommendationService->catalog()]);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/recommendations/preferences')]
	public function preferences(): DataResponse {
		return $this->answer(['read:accounts'], fn (): array => $this->recommendationService->get($this->viewer()->getUserId()));
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/recommendations/preferences')]
	public function savePreferences(int $if_revision = -1, array|string $topics = []): DataResponse {
		return $this->answer(['write:accounts'], function () use ($if_revision, $topics): DataResponse|array {
			if ($if_revision < 0) {
				throw new InvalidArgumentException('if_revision is required');
			}
			$decoded = is_string($topics) ? json_decode($topics, true) : $topics;
			if (!is_array($decoded) || !array_is_list($decoded)) {
				throw new InvalidArgumentException('topics must be a list');
			}
			$state = $this->recommendationService->replace($this->viewer()->getUserId(), $if_revision, $decoded);
			if ($state === null) {
				return new DataResponse(['error' => 'revision-conflict', 'current' => $this->recommendationService->get($this->viewer()->getUserId())], Http::STATUS_CONFLICT);
			}

			return $state;
		});
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/recommendations/reset')]
	public function reset(int $if_revision = -1): DataResponse {
		return $this->answer(['write:accounts'], function () use ($if_revision): DataResponse|array {
			if ($if_revision < 0) {
				throw new InvalidArgumentException('if_revision is required');
			}
			$state = $this->recommendationService->reset($this->viewer()->getUserId(), $if_revision);
			if ($state === null) {
				return new DataResponse(['error' => 'revision-conflict', 'current' => $this->recommendationService->get($this->viewer()->getUserId())], Http::STATUS_CONFLICT);
			}

			return $state;
		});
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/recommendations/export')]
	public function export(): DataResponse {
		return $this->answer(['read:accounts'], fn (): array => [
			'schema_version' => 1,
			'exported_at' => time(),
			'preferences' => $this->recommendationService->get($this->viewer()->getUserId()),
		]);
	}

	/** @param string[] $scopes @param callable(): mixed $callback */
	private function answer(array $scopes, callable $callback): DataResponse {
		try {
			$this->initViewer($scopes);
			if (!$this->recommendationService->isEnabled()) {
				throw new ItemNotFoundException('Recommendations are disabled');
			}
			$result = $callback();
			return $result instanceof DataResponse ? $result : new DataResponse($result);
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
