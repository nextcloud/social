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
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserManager;


class AccountController extends Controller {


	use TNCDataResponse;


	/** @var string */
	private $userId;

	/** @var IUserManager */
	private $userManager;

	/** @var ConfigService */
	private $configService;

	/** @var AccountService */
	private $actorService;

	/** @var MiscService */
	private $miscService;

	/** @var IAccountManager */
	private $accountManager;


	/**
	 * AccountController constructor.
	 *
	 * @param IRequest $request
	 * @param IUserManager $userManager
	 * @param ConfigService $configService
	 * @param AccountService $actorService
	 * @param MiscService $miscService
	 * @param IAccountManager $accountManager
	 * @param string $userId
	 */
	public function __construct(
		IRequest $request, $userId, IUserManager $userManager, ConfigService $configService,
		AccountService $actorService, MiscService $miscService,
		IAccountManager $accountManager
	) {
		parent::__construct(Application::APP_NAME, $request);

		$this->userId = $userId;
		$this->userManager = $userManager;
		$this->accountManager = $accountManager;

		$this->configService = $configService;
		$this->actorService = $actorService;
		$this->miscService = $miscService;
	}


	/**
	 * Called by the frontend to create a new Social account
	 *
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param string $username
	 *
	 * @return DataResponse
	 */
	public function create(string $username): DataResponse {
		try {
			$this->actorService->createActor($this->userId, $username);

			return $this->success([]);
		} catch (Exception $e) {
			return $this->fail($e);
		}
	}


	/**
	 *
	 * // TODO - is it still used ? maybe using info from LocalController !?
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @NoSubAdminRequired
	 *
	 * @param string $username
	 *
	 * @return DataResponse
	 */
	public function info(string $username): Response {
		$user = $this->userManager->get($username);
		if ($user === null) {
			// TODO: Proper handling of external accounts
			$props = [];
			$props['cloudId'] = $username;
			$props['displayname'] = ['value' => 'External account'];
			$props['posts'] = 1;
			$props['following'] = 2;
			$props['followers'] = 3;

			return new DataResponse($props);
		}
		$account = $this->accountManager->getAccount($user);
		/** @var IAccountProperty[] $props */
		$props = $account->getFilteredProperties(IAccountManager::VISIBILITY_PUBLIC, null);
		if ($this->userId !== null) {
			$props = array_merge(
				$props,
				$account->getFilteredProperties(IAccountManager::VISIBILITY_CONTACTS_ONLY, null)
			);
		}
		if (\array_key_exists('avatar', $props)) {
			$props['avatar']->setValue(
				\OC::$server->getURLGenerator()
							->linkToRouteAbsolute(
								'core.avatar.getAvatar', ['userId' => $username, 'size' => 128]
							)
			);
		}

		// Add counters
		$props['cloudId'] = $user->getCloudId();
		$props['posts'] = 1;
		$props['following'] = 2;
		$props['followers'] = 3;

		return new DataResponse($props);
	}


}

