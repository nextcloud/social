<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ChannelService;
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
 * The channels an account publishes videos under.
 *
 * A channel is what a video belongs to everywhere but here: PeerTube resolves
 * one by looking for a `Group` in a video's `attributedTo` and refuses the
 * video outright when there is none. This app makes one for an account the
 * first time it posts a video, so nobody has to know the word — these routes
 * are for the person who does and wants a second one, or a better name on the
 * first.
 *
 * Session routes rather than client-API ones: making an actor on this instance
 * mints an address other servers will follow and cache, which is not something
 * to hand to a third-party token.
 */
class ChannelController extends Controller {
	public function __construct(
		IRequest $request,
		private ?string $userId,
		private ChannelService $channelService,
		private AccountService $accountService,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Every channel this account has, oldest first. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/channels')]
	public function index(): DataResponse {
		return $this->answer(
			fn (): array => $this->channelService->forOwner($this->owner())
		);
	}

	/**
	 * Makes one.
	 *
	 * The handle is an address on this instance and cannot be changed
	 * afterwards, which is why it is asked for separately from the name.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/channels')]
	public function create(string $handle = '', string $name = '', string $description = ''): DataResponse {
		return $this->answer(
			fn (): array => [$this->channelService->create(
				$this->owner(), $handle, $name, $description
			)]
		);
	}

	/** Renames one, or changes what it says it is about. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/channels/{id}')]
	public function update(int $id, string $name = '', string $description = ''): DataResponse {
		return $this->answer(
			fn (): array => [$this->channelService->update($this->owner(), $id, $name, $description)]
		);
	}

	/**
	 * The account behind this request.
	 *
	 * @throws Throwable there is nobody, which `answer()` turns into a 401
	 */
	private function owner(): Person {
		if ($this->userId === null) {
			throw new InvalidResourceException('not logged in');
		}

		return $this->accountService->getActorFromUserId($this->userId);
	}

	/**
	 * The three routes differ by one call and answer the same shape: the
	 * channels the caller now has, so a client that has just changed one does
	 * not have to ask again to find out what it changed.
	 *
	 * @param callable(): array $action
	 */
	private function answer(callable $action): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse(['channels' => $action()], Http::STATUS_OK);
		} catch (InvalidResourceException $e) {
			// what is refused here is a handle that is taken or is not one, and
			// the message says which — the whole of the help there is
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->warning('a channel request failed', [
				'userId' => $this->userId, 'exception' => $e,
			]);

			return new DataResponse(['error' => 'request failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
