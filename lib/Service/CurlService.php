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


use daita\MySmallPhpTools\Exceptions\ArrayNotFoundException;
use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use daita\MySmallPhpTools\Model\Request;
use daita\MySmallPhpTools\Traits\TArrayTools;
use daita\MySmallPhpTools\Traits\TPathTools;
use Exception;
use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RequestContentException;
use OCA\Social\Exceptions\RequestResultNotJsonException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\RequestNetworkException;
use OCA\Social\Exceptions\RequestResultSizeException;
use OCA\Social\Exceptions\RequestServerException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Model\ActivityPub\Actor\Person;

class CurlService {


	use TArrayTools;
	use TPathTools;


	const ASYNC_REQUEST_TOKEN = '/async/request/{token}';
	const USER_AGENT = 'Nextcloud Social';


	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var int */
	private $maxDownloadSize = 0;

	/** @var bool */
	private $maxDownloadSizeReached = false;


	/**
	 * CurlService constructor.
	 *
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(ConfigService $configService, MiscService $miscService) {
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $account
	 *
	 * @return array
	 * @throws InvalidResourceException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RequestResultNotJsonException
	 */
	public function webfingerAccount(string $account): array {
		$account = $this->withoutBeginAt($account);

		// we consider an account is like an email
		if (!filter_var($account, FILTER_VALIDATE_EMAIL)) {
			throw new InvalidResourceException('account format is not valid');
		}

		list($username, $host) = explode('@', $account);
		if ($username === null || $host === null) {
			throw new InvalidResourceException();
		}

		$request = new Request('/.well-known/webfinger');
		$request->addData('resource', 'acct:' . $account);
		$request->setAddress($host);

		try {
			$result = $this->request($request);
		} catch (RequestNetworkException $e) {
			if ($e->getCode() === CURLE_COULDNT_CONNECT) {
				$request->setProtocol('http');
				$result = $this->request($request);
			} else throw $e;
		}

		return $result;
	}


	/**
	 * @param string $account
	 *
	 * @return Person
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RetrieveAccountFormatException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 */
	public function retrieveAccount(string $account): Person {
		$result = $this->webfingerAccount($account);

		try {
			$link = $this->extractArray('rel', 'self', $this->getArray('links', $result));
		} catch (ArrayNotFoundException $e) {
			throw new RetrieveAccountFormatException();
		}

		$id = $this->get('href', $link, '');
		$data = $this->retrieveObject($id);

		/** @var Person $actor */
		$actor = AP::$activityPub->getItemFromData($data);
		if ($actor->getType() !== Person::TYPE) {
			throw new ItemUnknownException();
		}

		if (strtolower($actor->getId()) !== strtolower($id)) {
			throw new InvalidOriginException();
		}

		return $actor;
	}


	/**
	 * @param $id
	 *
	 * @return array
	 * @throws MalformedArrayException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestServerException
	 * @throws RequestResultSizeException
	 * @throws RequestResultNotJsonException
	 */
	public function retrieveObject($id): array {

		$url = parse_url($id);
		$this->mustContains(['path', 'host', 'scheme'], $url);
		$request = new Request($url['path'], Request::TYPE_GET);
		$request->setAddress($url['host']);
		$request->setProtocol($url['scheme']);

		$result = $this->request($request);
		if (is_array($result)) {
			$result['_host'] = $request->getAddress();
		}

		return $result;
	}


	/**
	 * @param Request $request
	 *
	 * @return mixed
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RequestResultNotJsonException
	 */
	public function request(Request $request) {

		$this->maxDownloadSizeReached = false;
		$curl = $this->initRequest($request);

		$this->initRequestPost($curl, $request);
		$this->initRequestPut($curl, $request);
		$this->initRequestDelete($curl, $request);

		$result = curl_exec($curl);

		if ($this->maxDownloadSizeReached === true) {
			throw new RequestResultSizeException();
		}
		$this->parseRequestResult($curl, $request);

		if ($request->isBinary()) {
			$this->miscService->log('[>>] request (binary): ' . json_encode($request), 1);

			return $result;
		}

		$this->miscService->log(
			'[>>] request: ' . json_encode($request) . ' - result: ' . $result, 1
		);

		$result = json_decode((string)$result, true);
		if (is_array($result)) {
			return $result;
		}

		throw new RequestResultNotJsonException();
	}


	/**
	 * @param Request $request
	 */
	public function assignUserAgent(Request $request) {
		$request->setUserAgent(
			self::USER_AGENT . ' ' . $this->configService->getAppValue('installed_version')
		);
	}


	/**
	 * @param string $token
	 *
	 * @throws SocialAppConfigException
	 */
	public function asyncWithToken(string $token) {
		$address = $this->configService->getUrlSocial();
		$parse = parse_url($address);
		$host = $this->get('host', $parse, '');
		$path = $this->withEndSlash($this->get('path', $parse, '')) . $this->withoutBeginSlash(
				self::ASYNC_REQUEST_TOKEN
			);
		$path = str_replace('{token}', $token, $path);

		$request = new Request($path, Request::TYPE_POST);
		$request->setAddress($host);
		$request->setProtocol($this->get('scheme', $parse, 'https'));

		try {
			$this->request($request);
		} catch (Exception $e) {
			$this->miscService->log(
				'Cannot initiate AsyncWithToken ' . json_encode($token) . ' (' . get_class($e)
				. ' - ' . json_encode($e) . ')', 1
			);
		}
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

		curl_setopt($curl, CURLOPT_USERAGENT, $request->getUserAgent());
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $request->getTimeout());
		curl_setopt($curl, CURLOPT_TIMEOUT, $request->getTimeout());

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_BINARYTRANSFER, $request->isBinary());
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

		$this->maxDownloadSize =
			$this->configService->getAppValue(ConfigService::SOCIAL_MAX_SIZE) * (1024 * 1024);
		curl_setopt($curl, CURLOPT_BUFFERSIZE, 128);
		curl_setopt($curl, CURLOPT_NOPROGRESS, false);
		curl_setopt(
		/**
		 * @param $downloadSize
		 * @param int $downloaded
		 * @param $uploadSize
		 * @param int $uploaded
		 *
		 * @return int
		 */
			$curl, CURLOPT_PROGRESSFUNCTION,
			function($downloadSize, int $downloaded, $uploadSize, int $uploaded) {
				if ($downloaded > $this->maxDownloadSize) {
					$this->maxDownloadSizeReached = true;

					return 1;
				}

				return 0;
			}
		);

		return $curl;
	}


	/**
	 * @param Request $request
	 *
	 * @return resource
	 */
	private function generateCurlRequest(Request $request) {
		$url = $request->getProtocol() . '://' . $request->getAddress() . $request->getParsedUrl();
		if ($request->getType() !== Request::TYPE_GET) {
			$curl = curl_init($url);
		} else {
			$curl = curl_init($url . '?' . $request->getUrlData());
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
	 * @param resource $curl
	 * @param Request $request
	 *
	 * @throws RequestContentException
	 * @throws RequestServerException
	 * @throws RequestNetworkException
	 */
	private function parseRequestResult($curl, Request &$request) {
		$this->parseRequestResultCurl($curl, $request);

		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$request->setResultCode($code);

		$this->parseRequestResultCode301($code, $request);
		$this->parseRequestResultCode4xx($code, $request);
		$this->parseRequestResultCode5xx($code, $request);
	}


	/**
	 * @param resource $curl
	 * @param Request $request
	 *
	 * @throws RequestNetworkException
	 */
	private function parseRequestResultCurl($curl, Request $request) {
		$errno = curl_errno($curl);
		if ($errno > 0) {
			throw new RequestNetworkException(
				$errno . ' - ' . curl_error($curl) . ' - ' . json_encode(
					$request, JSON_UNESCAPED_SLASHES
				), $errno
			);
		}
	}


	/**
	 * @param int $code
	 * @param Request $request
	 *
	 * @throws RequestContentException
	 */
	private function parseRequestResultCode301($code, Request $request) {
		if ($code === 301) {
			throw new RequestContentException(
				'301 - ' . json_encode($request, JSON_UNESCAPED_SLASHES)
			);
		}
	}


	/**
	 * @param int $code
	 * @param Request $request
	 *
	 * @throws RequestContentException
	 */
	private function parseRequestResultCode4xx(int $code, Request $request) {
		if ($code === 404 || $code === 410) {
			throw new RequestContentException(
				$code . ' - ' . json_encode($request, JSON_UNESCAPED_SLASHES)
			);
		}
	}


	/**
	 * @param int $code
	 * @param Request $request
	 *
	 * @throws RequestServerException
	 */
	private function parseRequestResultCode5xx(int $code, Request $request) {
		if ($code === 500) {
			throw new RequestServerException(
				$code . ' - ' . json_encode($request, JSON_UNESCAPED_SLASHES)
			);
		}
	}

}

