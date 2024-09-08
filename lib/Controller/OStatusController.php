<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Exceptions\ArrayNotFoundException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IRequest;
use OCP\IUserSession;

class OStatusController extends Controller {
	use TNCDataResponse;
	use TArrayTools;

	private CacheActorService $cacheActorService;
	private AccountService $accountService;
	private CurlService $curlService;
	private MiscService $miscService;
	private IUserSession $userSession;
	private IInitialStateService $initialStateService;

	public function __construct(
		IRequest $request, IInitialStateService $initialStateService, CacheActorService $cacheActorService, AccountService $accountService,
		CurlService $curlService, MiscService $miscService, IUserSession $userSession
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->initialStateService = $initialStateService;
		$this->cacheActorService = $cacheActorService;
		$this->accountService = $accountService;
		$this->curlService = $curlService;
		$this->miscService = $miscService;
		$this->userSession = $userSession;
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function subscribe(string $uri): Response {
		try {
			try {
				$actor = $this->cacheActorService->getFromAccount($uri);
			} catch (InvalidResourceException $e) {
				$actor = $this->cacheActorService->getFromId($uri);
			}

			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new Exception('Failed to retrieve current user');
			}

			$this->initialStateService->provideInitialState('social', 'serverData', [
				'account' => $actor->getAccount(),
				'currentUser' => [
					'uid' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
				],
			]);
			return new TemplateResponse(
				'social', 'main', []
			);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function followRemote(string $local): Response {
		try {
			$following = $this->accountService->getActor($local);

			$this->initialStateService->provideInitialState('social', 'serverData', [
				'local' => $local,
				'account' => $following->getAccount(),
			]);
			return new TemplateResponse(
				'social', 'main', [], 'guest'
			);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 */
	public function getLink(string $local, string $account): Response {
		try {
			$following = $this->accountService->getActor($local);
			$result = $this->curlService->webfingerAccount($account);

			try {
				$link = $this->extractArray(
					'rel', 'http://ostatus.org/schema/1.0/subscribe',
					$this->getArray('links', $result)
				);
			} catch (ArrayNotFoundException $e) {
				throw new RetrieveAccountFormatException();
			}

			$template = $this->get('template', $link, '');
			$url = str_replace('{uri}', $following->getAccount(), $template);

			return $this->success(['url' => $url]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}
}
