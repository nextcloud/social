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
use OCA\Social\Exceptions\Request410Exception;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Model\ActivityPub\Actor\Person;

class CurlService {


	use TArrayTools;
	use TPathTools;


	const ASYNC_TOKEN = '/async/token/{token}';
	const USER_AGENT = 'Nextcloud Social';


	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


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
	 * @return Person
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws Request410Exception
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws RedundancyLimitException
	 * @throws UnknownItemException
	 * @throws InvalidOriginException
	 */
	public function retrieveAccount(string $account): Person {
		$account = $this->withoutBeginAt($account);

		if (strstr(substr($account, 0, -3), '@') === false) {
			throw new InvalidResourceException();
		}
		list($username, $host) = explode('@', $account);

//		if ($username === null || $host === null) {
//			throw new InvalidResourceException();
//		}

		$request = new Request('/.well-known/webfinger');
		$request->addData('resource', 'acct:' . $account);
		$request->setAddress($host);
		$result = $this->request($request);

		try {
			$link = $this->extractArray('rel', 'self', $this->getArray('links', $result));
		} catch (ArrayNotFoundException $e) {
			throw new RequestException();
		}

		$id = $this->get('href', $link, '');
		$data = $this->retrieveObject($id);

		/** @var Person $actor */
		$actor = AP::$activityPub->getItemFromData($data);
		if ($actor->getType() !== Person::TYPE) {
			throw new UnknownItemException();
		}

		if ($actor->getId() !== $id) {
			throw new InvalidOriginException();
		}

		return $actor;
	}


	/**
	 * @param $id
	 *
	 * @return array
	 * @throws MalformedArrayException
	 * @throws Request410Exception
	 * @throws RequestException
	 */
	public function retrieveObject($id): array {

		$url = parse_url($id);
		$this->mustContains(['path', 'host'], $url);
		$request = new Request($url['path'], Request::TYPE_GET);
		$request->setAddress($url['host']);

		$result = $this->request($request);
		if (is_array($result)) {
			$result['_host'] = $url['host'];
		}

		return $result;
	}


	/**
	 * @param Request $request
	 *
	 * @return array
	 * @throws RequestException
	 * @throws Request410Exception
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
		$this->parseRequestResultCode410($code);
//		$this->parseRequestResultCode503($code);
//		$this->parseRequestResultCode500($code);
//		$this->parseRequestResult($result);

		$ret = json_decode((string)$result, true);
//		if ($ret === null) {
//			throw new RequestException('500 Internal server error - could not parse JSON response');
//		}
		if (!is_array($ret)) {
			$ret = ['_result' => $result];
		}

		$ret['_address'] = $request->getAddress();
		$ret['_path'] = $request->getUrl();
		$ret['_code'] = $code;

		$this->miscService->log(
			'[>>] request: ' . json_encode($request) . ' - result: ' . json_encode($ret), 1
		);

		return $ret;
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
				self::ASYNC_TOKEN
			);
		$path = str_replace('{token}', $token, $path);

		$request = new Request($path, Request::TYPE_POST);
		$request->setAddress($host);
		try {
			$this->request($request);
		} catch (Exception $e) {
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
	 * @throws Request410Exception
	 */
	private function parseRequestResultCode404(int $code, Request $request) {
		if ($code === 404) {
			throw new Request410Exception('404 Not Found - ' . json_encode($request));
		}
	}

	/**
	 * @param int $code
	 *
	 * @throws Request410Exception
	 */
	private function parseRequestResultCode410(int $code) {
		if ($code === 410) {
			throw new Request410Exception();
		}
	}


}
