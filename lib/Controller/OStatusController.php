<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Controller;


use daita\MySmallPhpTools\Exceptions\ArrayNotFoundException;
use daita\MySmallPhpTools\Traits\Nextcloud\TNCDataResponse;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;


class OStatusController extends Controller {


	use TNCDataResponse;
	use TArrayTools;


	/** @var IUserManager */
	private $userSession;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var StreamService */
	private $streamService;

	/** @var AccountService */
	private $accountService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/**
	 * OStatusController constructor.
	 *
	 * @param IUserSession $userSession
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param StreamService $streamService
	 * @param CacheActorService $cacheActorService
	 * @param AccountService $accountService
	 * @param CurlService $curlService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IUserSession $userSession, IRequest $request, IURLGenerator $urlGenerator,
		StreamService $streamService, CacheActorService $cacheActorService, AccountService $accountService,
		CurlService $curlService, MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->cacheActorService = $cacheActorService;
		$this->streamService = $streamService;
		$this->accountService = $accountService;
		$this->curlService = $curlService;
		$this->miscService = $miscService;
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 *
	 * @param string $uri
	 *
	 * @return Response
	 */
	public function subscribeOld(string $uri): Response {
		return $this->subscribe($uri);
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 *
	 * @param string $uri
	 *
	 * @return Response
	 * @throws Exception
	 */
	public function subscribe(string $uri): Response {
		try {
			$actor = $this->cacheActorService->getFromAccount($uri);

			return $this->subscribeLocalAccount($actor);
		} catch (Exception $e) {
		}

		try {
			$post = $this->streamService->getStreamById($uri, true, true);

			$link = $this->urlGenerator->linkToRouteAbsolute('social.SocialPub.displayRemotePost')
					. '?id=' . $uri;

			return new RedirectResponse($link);
		} catch (Exception $e) {
		}

		return $this->fail(new Exception('unknown protocol'));
	}


	/**
	 * @param Person $actor
	 *
	 * @return Response
	 */
	private function subscribeLocalAccount(Person $actor): Response {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new Exception('Failed to retrieve current user');
			}

			return new TemplateResponse(
				'social', 'ostatus', [
				'serverData' => [
					'account'     => $actor->getAccount(),
					'currentUser' => [
						'uid'         => $user->getUID(),
						'displayName' => $user->getDisplayName(),
					]
				]
			], 'guest'
			);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @param string $local
	 *
	 * @return Response
	 */
	public function followRemote(string $local): Response {
		try {
			$following = $this->accountService->getActor($local);

			return new TemplateResponse(
				'social', 'ostatus', [
				'serverData' => [
					'local'   => $local,
					'account' => $following->getAccount()
				]
			], 'guest'
			);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @param string $local
	 * @param string $account
	 *
	 * @return Response
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

