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

namespace OCA\Social\Service;


use daita\Traits\TArrayTools;
use Exception;
use Lrf141\OAuth2\Client\Provider\Mastodon;
use OCA\Social\Db\ServiceAccountsRequest;
use OCA\Social\Db\ServicesRequest;
use OCA\Social\Exceptions\ServiceAccountAlreadyExistException;
use OCA\Social\Exceptions\ServiceAccountDoesNotExistException;
use OCA\Social\Exceptions\ServiceAccountException;
use OCA\Social\Model\Service;
use OCA\Social\Model\ServiceAccount;
use OCA\Social\Traits\TOAuth2;

class ServiceAccountsService {


	use TOAuth2;
	use TArrayTools;


	/** @var ServicesRequest */
	private $servicesRequest;

	/** @var ServiceAccountsRequest */
	private $serviceAccountsRequest;

	/** @var ActivityStreamsService */
	private $activityStreamsService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ServiceAccountsService constructor.
	 *
	 * @param ServicesRequest $servicesRequest
	 * @param ServiceAccountsRequest $serviceAccountsRequest
	 * @param ActivityStreamsService $activityStreamsService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ServicesRequest $servicesRequest, ServiceAccountsRequest $serviceAccountsRequest,
		ActivityStreamsService $activityStreamsService, MiscService $miscService
	) {
		$this->servicesRequest = $servicesRequest;
		$this->serviceAccountsRequest = $serviceAccountsRequest;
		$this->activityStreamsService = $activityStreamsService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $userId
	 *
	 * @return ServiceAccount[]
	 * @throws Exception
	 */
	public function getAvailableAccounts(string $userId): array {
		$service = $this->serviceAccountsRequest->getAvailableAccounts($userId);

		return $service;
	}


	/**
	 * @param string $userId
	 * @param int $accountId
	 *
	 * @return ServiceAccount
	 * @throws ServiceAccountDoesNotExistException
	 */
	public function getAccount(string $userId, int $accountId): ServiceAccount {
		$account = $this->serviceAccountsRequest->getAccount($accountId);

		if ($account->getUserId() !== $userId) {
			throw new ServiceAccountDoesNotExistException('Wrong owner');
		}

		return $account;
	}


	/**
	 * @param Service $service
	 *
	 * @return string
	 */
	public function getAuthorizationUrl(Service $service): string {
		$mastodon = $this->generateMastodonAuth($service);

		return $mastodon->getAuthorizationUrl();
	}


	/**
	 * @param string $userId
	 * @param int $serviceId
	 * @param string $code
	 *
	 * @throws Exception
	 */
	public function generateAccount(string $userId, int $serviceId, string $code) {

		$service = $this->servicesRequest->getService($serviceId);
		$mastodon = $this->generateMastodonAuth($service);

		$token =
			$mastodon->getAccessToken('authorization_code', ['code' => $code]);

		$account = new ServiceAccount();
		$account->setAuth('token', $token->getToken());
//			$account->setAuth('refresh_token', $token->getRefreshToken());
		$account->setService($service);
		$account->setUserId($userId);
		$account->setStatus(1);

		$info = $this->activityStreamsService->getAccountInformation($account);
		$accountName = '@' . $this->get('username', $info, '');
		$this->checkAccountUniqueness($serviceId, $userId, $accountName);

		$account->setAccount($accountName);
		$account->setAccountId($this->getInt('id', $info, 0));
		$this->serviceAccountsRequest->create($account);
	}


	/**
	 * @param int $serviceId
	 * @param string $userId
	 * @param string $accountName
	 *
	 * @throws ServiceAccountAlreadyExistException
	 * @throws ServiceAccountException
	 */
	private function checkAccountUniqueness(int $serviceId, string $userId, string $accountName) {

		if ($accountName === '@') {
			throw new ServiceAccountException('Account name is not valid');
		}

		try {
			$this->serviceAccountsRequest->getFromAccountName($serviceId, $userId, $accountName);
			throw new ServiceAccountAlreadyExistException('This account already exist');
		} catch (ServiceAccountDoesNotExistException $e) {
			/** we do nohtin' */
		}
	}


	/**
	 * @param Service $service
	 *
	 * @return Mastodon
	 */
	private function generateMastodonAuth(Service $service): Mastodon {
		return new Mastodon(
			[
				'clientId'     => $service->getConfig('clientKey'),
				'clientSecret' => $service->getConfig('clientSecret'),
				'redirectUri'  => $this->generateRedirectUrl($service->getId()),
				'instance'     => 'https://' . $service->getAddress(),
				'scope'        => 'read write follow'
			]
		);
	}


}

