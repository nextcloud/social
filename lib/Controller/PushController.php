<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\RefusalException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\PushSubscriptionService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\VapidService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/** Mastodon's per-access-token Web Push subscription API. */
class PushController extends MastodonApiController {
	public function __construct(
		IRequest $request,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		LoggerInterface $logger,
		ClientService $clientService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		StreamService $streamService,
		FollowService $followService,
		private PushSubscriptionService $subscriptions,
		private VapidService $vapid,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/push/subscription')]
	public function pushSubscriptionGet(): DataResponse {
		try {
			$this->initViewer(true);
			$this->requireUserToken();
			$subscription = $this->subscriptions->forToken($this->viewer->getUserId(), $this->tokenKey());
			if ($subscription === null) {
				throw new ItemNotFoundException('Push subscription not found');
			}

			return new DataResponse($this->asResponse($subscription), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/push/subscription')]
	public function pushSubscriptionCreate(): DataResponse {
		try {
			$this->initViewer(true);
			$this->requireUserToken();
			$subscription = $this->params('subscription', 'push-subscription-invalid');
			$keys = $subscription['keys'] ?? null;
			if (!is_array($keys)) {
				throw new RefusalException('The subscription keys are required', 'push-keys-invalid');
			}
			$data = $this->params('data', 'push-data-invalid');
			$alerts = $data['alerts'] ?? [];
			if (!is_array($alerts)) {
				throw new RefusalException('The push alert preferences are invalid', 'push-alerts-invalid');
			}
			$standard = $subscription['standard'] ?? false;
			if (!is_bool($standard) && !in_array($standard, [0, 1, '0', '1', 'true', 'false'], true)) {
				throw new RefusalException('The standard push format flag is invalid', 'push-standard-invalid');
			}
			$saved = $this->subscriptions->save(
				$this->viewer->getUserId(),
				$this->tokenKey(),
				$this->bearer,
				(string)($subscription['endpoint'] ?? ''),
				(string)($keys['p256dh'] ?? ''),
				(string)($keys['auth'] ?? ''),
				$alerts,
				(string)($data['policy'] ?? 'all'),
				in_array($standard, [true, 1, '1', 'true'], true),
			);

			return new DataResponse($this->asResponse($saved), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/push/subscription')]
	public function pushSubscriptionUpdate(): DataResponse {
		try {
			$this->initViewer(true);
			$this->requireUserToken();
			$current = $this->subscriptions->forToken($this->viewer->getUserId(), $this->tokenKey());
			if ($current === null) {
				throw new ItemNotFoundException('Push subscription not found');
			}
			$data = $this->params('data', 'push-data-invalid');
			$alerts = $data['alerts'] ?? [];
			if (!is_array($alerts)) {
				throw new RefusalException('The push alert preferences are invalid', 'push-alerts-invalid');
			}
			$saved = $this->subscriptions->update(
				$this->viewer->getUserId(),
				$this->tokenKey(),
				$alerts,
				(string)($data['policy'] ?? $current['policy']),
			);
			if ($saved === null) {
				throw new ItemNotFoundException('Push subscription not found');
			}

			return new DataResponse($this->asResponse($saved), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/push/subscription')]
	public function pushSubscriptionDelete(): DataResponse {
		try {
			$this->initViewer(true);
			$this->requireUserToken();
			$this->subscriptions->delete($this->viewer->getUserId(), $this->tokenKey());

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	private function requireUserToken(): void {
		if ($this->bearer === '' || $this->client === null || $this->viewer === null) {
			throw new InsufficientScopeException('A user access token with the push scope is required');
		}
	}

	private function tokenKey(): string {
		return hash('sha256', $this->bearer);
	}

	/** @return array<string, mixed> */
	private function params(string $key, string $errorCode): array {
		$value = $this->request->getParam($key, []);
		if (!is_array($value)) {
			throw new RefusalException('The ' . $key . ' parameter must be an object', $errorCode);
		}

		return $value;
	}

	/** @param array{id: string, token: string, accessToken?: string, endpoint: string, p256dh: string, auth: string, standard: bool, alerts: array<string, bool>, policy: string, created: int} $subscription */
	private function asResponse(array $subscription): array {
		return [
			'id' => (int)$subscription['id'],
			'endpoint' => $subscription['endpoint'],
			'standard' => $subscription['standard'],
			'alerts' => $subscription['alerts'],
			'server_key' => $this->vapid->publicKey(),
			'policy' => $subscription['policy'],
		];
	}
}
