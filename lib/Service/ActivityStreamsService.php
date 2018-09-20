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


use daita\Model\Request;
use Exception;
use OCA\Social\Db\ServiceAccountsRequest;
use OCA\Social\Exceptions\ActivityStreamsRequestException;
use OCA\Social\Exceptions\InvalidAccessTokenException;
use OCA\Social\Model\ServiceAccount;
use OCA\Social\Traits\TOAuth2;

class ActivityStreamsService {


	const URL_CREATE_APP = '/api/v1/apps';
	const URL_VERIFY_ACCOUNT = '/api/v1/accounts/verify_credentials';
	const URL_TEST = '/api/v1/accounts/verify_credentials';
	const URL_ACCOUNT_STATUSES = '/api/v1/accounts/:id/statuses';
	const URL_ACCOUNT_FOLLOWS = '/api/v1/accounts/:id/following';
	const URL_ACCOUNT_FOLLOWERS = '/api/v1/accounts/:id/followers';


	use TOAuth2;

	/** @var ServiceAccountsRequest */
	private $serviceAccountsRequest;

	/** @var ConfigService */
	private $configService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ActivityStreamsService constructor.
	 *
	 * @param ServiceAccountsRequest $serviceAccountsRequest
	 * @param ConfigService $configService
	 * @param CurlService $curlService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ServiceAccountsRequest $serviceAccountsRequest, ConfigService $configService,
		CurlService $curlService, MiscService $miscService
	) {
		$this->serviceAccountsRequest = $serviceAccountsRequest;
		$this->configService = $configService;
		$this->curlService = $curlService;
		$this->miscService = $miscService;
	}


	/**
	 * @param ServiceAccount $account
	 *
	 * @return array
	 * @throws Exception
	 */
	public function test(ServiceAccount $account) {
		$request = new Request(self::URL_TEST, Request::TYPE_GET);

		return $this->request($account, $request);
	}


	/**
	 * @param ServiceAccount $account
	 *
	 * @return array
	 * @throws Exception
	 */
	public function accountStatus(ServiceAccount $account) {
		$request = new Request(self::URL_ACCOUNT_STATUSES, Request::TYPE_GET);
		$request->addDataInt('id', $account->getAccountId());

		return $this->request($account, $request);
	}


	/**
	 * @param ServiceAccount $account
	 *
	 * @return array
	 * @throws Exception
	 */
	public function accountFollows(ServiceAccount $account) {
		$request = new Request(self::URL_ACCOUNT_FOLLOWS, Request::TYPE_GET);
		$request->addDataInt('id', $account->getAccountId());

		return $this->request($account, $request);
	}


	/**
	 * @param ServiceAccount $account
	 *
	 * @return array
	 * @throws Exception
	 */
	public function getAccountInformation(ServiceAccount $account) {
		$request = new Request(self::URL_VERIFY_ACCOUNT, Request::TYPE_GET);

		return $this->request($account, $request);
	}


	/**
	 * @param ServiceAccount $account
	 * @param Request $request
	 *
	 * @return array
	 * @throws ActivityStreamsRequestException
	 */
	private function request(ServiceAccount $account, Request $request) {
		try {
			return $this->curlService->request($account, $request, true);
		} catch (InvalidAccessTokenException $e) {
//			$this->oAuth2TokensRequest->resetToken($auth);
			throw new ActivityStreamsRequestException($e->getMessage());
		} catch (Exception $e) {
			$message = 'Issue with ' . json_encode($request) . ' - ' . get_class($e) . ' - '
					   . $e->getMessage();

			$this->miscService->log($message);
			throw new ActivityStreamsRequestException($message);
		}
	}

}
