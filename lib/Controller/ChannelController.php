<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ChannelService;
use OCA\Social\Service\ClientService;
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
 * Client-API routes: reading them asks for `read:accounts`, and making or
 * changing one for `write:accounts`, the scope that changes the profile. A
 * channel is an actor on this instance like the profile is — an address other
 * servers will follow and cache — so a token that may not edit the profile may
 * not mint one either.
 */
class ChannelController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private ChannelService $channelService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/** Every channel this account has, oldest first. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/channels')]
	public function index(): DataResponse {
		return $this->answer(
			['read:accounts'],
			fn (): array => $this->channelService->forOwner($this->viewer())
		);
	}

	/**
	 * Makes one.
	 *
	 * The handle is an address on this instance and cannot be changed
	 * afterwards, which is why it is asked for separately from the name.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 5, period: 3600)]
	#[UserRateLimit(limit: 5, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/channels')]
	public function create(string $handle = '', string $name = '', string $description = ''): DataResponse {
		return $this->answer(
			['write:accounts'],
			fn (): array => [$this->channelService->create(
				$this->viewer(), $handle, $name, $description
			)]
		);
	}

	/** Renames one, or changes what it says it is about. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/channels/{id}')]
	public function update(int $id, string $name = '', string $description = ''): DataResponse {
		return $this->answer(
			['write:accounts'],
			fn (): array => [$this->channelService->update($this->viewer(), $id, $name, $description)]
		);
	}

	/**
	 * The three routes differ by the scope they ask for and one call, and
	 * answer the same shape: the channels the caller now has, so a client that
	 * has just changed one does not have to ask again to find out what it
	 * changed.
	 *
	 * @param string[] $scopes
	 * @param callable(): array $action
	 */
	private function answer(array $scopes, callable $action): DataResponse {
		try {
			$this->initViewer($scopes);

			return new DataResponse(['channels' => $action()], Http::STATUS_OK);
		} catch (ClientNotFoundException|InsufficientScopeException $e) {
			return $this->error($e);
		} catch (InvalidResourceException $e) {
			// what is refused here is a handle that is taken or is not one, and
			// the message says which — the whole of the help there is
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->warning('a channel request failed', [
				'userId' => $this->viewer?->getUserId(), 'exception' => $e,
			]);

			return new DataResponse(['error' => 'request failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
