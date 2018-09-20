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
use OCA\Social\Exceptions\APIRequestException;
use OCA\Social\Exceptions\InvalidAccessTokenException;
use OCA\Social\Exceptions\MovedPermanentlyException;
use OCA\Social\Model\ServiceAccount;

class CurlService {


	/** @var MiscService */
	private $miscService;


	/**
	 * CurlService constructor.
	 *
	 * @param MiscService $miscService
	 */
	public function __construct(MiscService $miscService) {
		$this->miscService = $miscService;
	}


	/**
	 * @param ServiceAccount $account
	 * @param Request $request
	 * @param bool $authed
	 *
	 * @return array
	 * @throws InvalidAccessTokenException
	 * @throws MovedPermanentlyException
	 * @throws APIRequestException
	 */
	public function request(ServiceAccount $account, Request $request, bool $authed = true) {

		$curl = $this->initRequest($account, $request, $authed);
		$this->initRequestPost($curl, $request);
		$this->initRequestPut($curl, $request);
		$this->initRequestDelete($curl, $request);

		$result = curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$this->parseRequestResultCode301($code);
		$this->parseRequestResultCode401($code);
		$this->parseRequestResultCode404($code);
//		$this->parseRequestResultCode503($code);
//		$this->parseRequestResultCode500($code);
//		$this->parseRequestResult($result);

		return json_decode($result, true);
	}


	/**
	 * @param ServiceAccount $account
	 * @param Request $request
	 * @param bool $authed
	 *
	 * @return resource
	 */
	private function initRequest(ServiceAccount $account, Request $request, bool $authed) {

		$curl = $this->generateCurlRequest($account, $request);
		$headers = [];

		if ($authed) {
			$headers[] = 'Authorization: Bearer ' . $account->getAuth('token');
		}


		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($curl, CURLOPT_TIMEOUT, 20);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

		return $curl;
	}


	/**
	 * @param ServiceAccount $account
	 * @param Request $request
	 *
	 * @return resource
	 */
	private function generateCurlRequest(ServiceAccount $account, Request $request) {
		$service = $account->getService();
		$url = 'https://' . $service->getAddress() . $request->getParsedUrl();

		if ($request->getType() !== Request::TYPE_GET) {
			$curl = curl_init($url);
		} else {
			$curl = curl_init($url . '?' . $request->getDataBody());
		}

		return $curl;
	}


	/**
	 * @param resource $curl
	 * @param Request $request
	 */
	private function initRequestPost($curl, Request $request) {
		if ($request->getType() !== Request::TYPE_POST) {
			return;
		}

		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $request->getDataBody());
	}


	/**
	 * @param resource $curl
	 * @param Request $request
	 */
	private function initRequestPut($curl, Request $request) {
		if ($request->getType() !== Request::TYPE_PUT) {
			return;
		}

		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
		curl_setopt($curl, CURLOPT_POSTFIELDS, $request->getDataBody());
	}


	/**
	 * @param resource $curl
	 * @param Request $request
	 */
	private function initRequestDelete($curl, Request $request) {
		if ($request->getType() !== Request::TYPE_DELETE) {
			return;
		}

		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
		curl_setopt($curl, CURLOPT_POSTFIELDS, $request->getDataBody());
	}


	/**
	 * @param int $code
	 *
	 * @throws MovedPermanentlyException
	 */
	private function parseRequestResultCode301($code) {
		if ($code === 301) {
			throw new MovedPermanentlyException('301 Moved Permanently');
		}
	}

	/**
	 * @param int $code
	 *
	 * @throws InvalidAccessTokenException
	 */
	private function parseRequestResultCode401($code) {
		if ($code === 401) {
			throw new InvalidAccessTokenException('401 Access Token Invalid');
		}
	}

	/**
	 * @param int $code
	 *
	 * @throws APIRequestException
	 */
	private function parseRequestResultCode404($code) {
		if ($code === 404) {
			throw new APIRequestException('404 Not Found');
		}
	}


}
