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


use daita\MySmallPhpTools\Model\Request;
use OCA\Social\Exceptions\RequestException;

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
	 * @param Request $request
	 *
	 * @return array
	 * @throws RequestException
	 */
	public function request(Request $request): array {
		$curl = $this->initRequest($request);

		$this->initRequestPost($curl, $request);
		$this->initRequestPut($curl, $request);
		$this->initRequestDelete($curl, $request);

		$result = curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		$this->parseRequestResultCode301($code);
//		$this->parseRequestResultCode401($code);
		$this->parseRequestResultCode404($code, $request);
//		$this->parseRequestResultCode503($code);
//		$this->parseRequestResultCode500($code);
//		$this->parseRequestResult($result);

		$ret = json_decode($result, true);
		if (!is_array($ret)) {
			$ret = ['_result' => $result];
		}

		$ret['_address'] = $request->getAddress();
		$ret['_code'] = $code;

		return $ret;
	}


	/**
	 * @param Request $request
	 *
	 * @return resource
	 */
	private function initRequest(Request $request) {

		$curl = $this->generateCurlRequest($request);
		$headers = $request->getHeaders();

		$headers[] = 'Accept: application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($curl, CURLOPT_TIMEOUT, 20);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

		return $curl;
	}


	/**
	 * @param Request $request
	 *
	 * @return resource
	 */
	private function generateCurlRequest(Request $request) {
		$url = 'https://' . $request->getAddress() . $request->getParsedUrl();
//		echo 'curl: ' . $request->getUrl() . "\n";
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
	 * @throws RequestException
	 */
	private function parseRequestResultCode301($code) {
		if ($code === 301) {
			throw new RequestException('301 Moved Permanently');
		}
	}


	/**
	 * @param int $code
	 *
	 * @param Request $request
	 *
	 * @throws RequestException
	 */
	private function parseRequestResultCode404(int $code, Request $request) {
		if ($code === 404) {
			throw new RequestException('404 Not Found - ' . json_encode($request));
		}
	}


}
