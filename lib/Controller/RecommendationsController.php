<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\RecommendationCandidateService;
use OCA\Social\Service\RecommendationFeedbackService;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationTimelineService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
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
		private RecommendationCandidateService $candidateService,
		private RecommendationFeedbackService $feedbackService,
		private RecommendationTimelineService $timelineService,
		private FilterService $filterService,
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
			'feedback' => $this->feedbackService->export($this->viewer()->getUserId()),
		]);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/recommendations/context/{postId}')]
	public function feedbackContext(string $postId): DataResponse {
		return $this->answer(['read:statuses'], fn (): array
			=> $this->feedbackService->context($this->viewer(), $postId)
		);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/recommendations/feedback')]
	public function feedback(string $event_id = '', string $post_id = '', string $kind = '', string $topic_id = ''): DataResponse {
		return $this->answer(['write:statuses'], fn (): array
			=> $this->feedbackService->record($this->viewer(), $event_id, $post_id, $kind, $topic_id)
		);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/recommendations/feedback/{feedbackId}')]
	public function undoFeedback(string $feedbackId): DataResponse {
		return $this->answer(['write:statuses'], fn (): array
			=> $this->feedbackService->undo($this->viewer(), $feedbackId)
		);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/recommendations/candidates/{topicId}')]
	public function candidates(string $topicId, int $limit = 20, string $cursor = ''): DataResponse {
		return $this->answer(['read:accounts'], fn (): array
			=> $this->candidateService->discover($this->viewer(), $topicId, $limit, $cursor)
		);
	}

	/** The web For You feed, with an envelope for its versioned session cursor. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/recommendations/timeline')]
	public function timeline(int $limit = 20, string $cursor = '', string $media = ''): DataResponse {
		return $this->answer(['read:statuses'], function () use ($limit, $cursor, $media): array {
			$viewer = $this->viewer();
			$limit = max(1, min(RecommendationCandidateService::MAX_PAGE_SIZE, $limit));
			$page = $this->timelineService->page($viewer, $limit, $cursor, $media);
			$result = $page;
			$items = [];

			// The legacy timeline applies hide filters after ranking. Apply the
			// same public-context rules here, and advance the immutable snapshot
			// while a hidden page leaves room for more visible results.
			for ($scan = 0; $scan < 5; $scan++) {
				$items = array_merge($items, $this->filterService->apply($page['items'], Filter::CONTEXT_PUBLIC, $viewer));
				if (count($items) >= $limit || $page['next_cursor'] === null) {
					break;
				}
				$page = $this->timelineService->page($viewer, $limit - count($items), $page['next_cursor'], $media);
				$result['next_cursor'] = $page['next_cursor'];
				$result['cursor_expired'] = $result['cursor_expired'] || $page['cursor_expired'];
			}

			$result['items'] = array_slice($items, 0, $limit);
			$result['coverage']['returned'] = count($result['items']);
			if ($result['mode'] === 'RANKED' && count($result['items']) < min($limit, 15)) {
				$result['coverage']['state'] = 'UNDERFILLED';
			}
			return $result;
		});
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
