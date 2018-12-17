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


use daita\MySmallPhpTools\Traits\Nextcloud\TNCDataResponse;
use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;

class SocialPubController extends Controller {


	use TNCDataResponse;

	/** @var string */
	private $userId;

	/** @var IL10N */
	private $l10n;

	/** @var AccountService */
	private $accountService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var FollowService */
	private $followService;

	/** @var MiscService */
	private $miscService;


	/**
	 * SocialPubController constructor.
	 *
	 * @param $userId
	 * @param IRequest $request
	 * @param IL10N $l10n
	 * @param AccountService $accountService
	 * @param CacheActorService $cacheActorService
	 * @param FollowService $followService
	 * @param MiscService $miscService
	 */
	public function __construct(
		$userId, IRequest $request, IL10N $l10n, AccountService $accountService,
		CacheActorService $cacheActorService, FollowService $followService, MiscService $miscService
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->followService = $followService;
		$this->miscService = $miscService;
	}


	/**
	 * return webpage content for human navigation.
	 * Should return information about a Social account, based on username.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return Response
	 */
	public function actor(string $username): Response {

		try {
			$actor = $this->cacheActorService->getFromLocalAccount($username);
			$actor->setCompleteDetails(true);

			$logged = false;
			$ownAccount = false;
			if ($this->userId !== null) {
				$logged = true;
				$local = $this->accountService->getActorFromUserId($this->userId, true);
				if ($local->getId() === $actor->getId()) {
					$ownAccount = true;
				} else {
					$this->fillActorWithLinks($actor, $local);
				}
			}

			$data = [
				'serverData' => [
					'public' => true,
				],
				'actor'      => $actor,
				'logged'     => $logged,
				'ownAccount' => $ownAccount
			];


			$page = new PublicTemplateResponse(Application::APP_NAME, 'main', $data);
			$page->setHeaderTitle($this->l10n->t('Social') . ' ' . $username);

			return $page;
		} catch (CacheActorDoesNotExistException $e) {
			return new NotFoundResponse();
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 * return webpage content for human navigation.
	 * Should return followers of a Social account, based on username.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return TemplateResponse
	 */
	public function followers(string $username): TemplateResponse {
		return new TemplateResponse(Application::APP_NAME, 'followers', [], 'blank');
	}


	/**
	 * return webpage content for human navigation.
	 * Should return following of a Social account, based on username.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 *
	 * @return TemplateResponse
	 */
	public function following(string $username): TemplateResponse {
		return new TemplateResponse(Application::APP_NAME, 'following', [], 'blank');
	}


	/**
	 * Should return post, do nothing.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $username
	 * @param $postId
	 *
	 * @return Response
	 */
	public function displayPost(string $username, int $postId) {
		return $this->success([$username, $postId]);
	}


	/**
	 * @param Person $actor
	 * @param Person $local
	 */
	private function fillActorWithLinks(Person $actor, Person $local) {
		$links = $this->followService->getLinksBetweenPersons($local, $actor);
		$actor->addDetailArray('link', $links);
	}

}

