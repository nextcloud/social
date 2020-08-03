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
use daita\MySmallPhpTools\Exceptions\RequestContentException;
use daita\MySmallPhpTools\Exceptions\RequestNetworkException;
use daita\MySmallPhpTools\Exceptions\RequestResultNotJsonException;
use daita\MySmallPhpTools\Exceptions\RequestResultSizeException;
use daita\MySmallPhpTools\Exceptions\RequestServerException;
use daita\MySmallPhpTools\Model\Request;
use daita\MySmallPhpTools\Traits\TArrayTools;
use daita\MySmallPhpTools\Traits\TPathTools;
use daita\MySmallPhpTools\Traits\TRequest;
use Exception;
use OCA\Social\AP;
use OCA\Social\Exceptions\HostMetaException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Actor\Person;

class CurlService {


	use TArrayTools;
	use TPathTools;
	use TRequest {
		initRequestPost as initRequestPostOrig;
		initRequestGet as initRequestGetOrig;
		retrieveJson as retrieveJsonOrig;
		doRequest as doRequestOrig;
	}


	const ASYNC_REQUEST_TOKEN = '/async/request/{token}';
	const USER_AGENT = 'Nextcloud Social';


	/** @var ConfigService */
	private $configService;

	/** @var FediverseService */
	private $fediverseService;

	/** @var MiscService */
	private $miscService;


	/**
	 * CurlService constructor.
	 *
	 * @param ConfigService $configService
	 * @param FediverseService $fediverseService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ConfigService $configService, FediverseService $fediverseService, MiscService $miscService
	) {
		$this->configService = $configService;
		$this->fediverseService = $fediverseService;
		$this->miscService = $miscService;

		$maxDlSize = $this->configService->getAppValue(ConfigService::SOCIAL_MAX_SIZE) * (1024 * 1024);
		$this->setMaxDownloadSize($maxDlSize);
	}


	/**
	 * @param string $account
	 *
	 * @return array
	 * @throws InvalidResourceException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function webfingerAccount(string &$account): array {
		$account = $this->withoutBeginAt($account);

		// we consider an account is like an email
		if (!filter_var($account, FILTER_VALIDATE_EMAIL)) {
			throw new InvalidResourceException('account format is not valid');
		}

		list($username, $host) = explode('@', $account);
		if ($username === null || $host === null) {
			throw new InvalidResourceException();
		}

		$protocols = ['https', 'http'];
		try {
			$path = $this->hostMeta($host, $protocols);
		} catch (HostMetaException $e) {
			$path = '/.well-known/webfinger';
		}

		$request = new Request($path);
		$request->addData('resource', 'acct:' . $account);
		$request->setAddress($host);
		$request->setProtocols($protocols);
		$result = $this->retrieveJson($request);

		$subject = $this->get('subject', $result, '');
		list($type, $temp) = explode(':', $subject, 2);
		if ($type === 'acct') {
			$account = $temp;
		}

		return $result;
	}


	/**
	 * @param string $host
	 * @param array $protocols
	 *
	 * @return string
	 * @throws HostMetaException
	 */
	public function hostMeta(string &$host, array &$protocols): string {
		$request = new Request('/.well-known/host-meta');
		$request->setAddress($host);
		$request->setProtocols($protocols);

		try {
			$result = $this->retrieveJson($request);
		} catch (Exception $e) {
			$this->miscService->log('hostMeta Exception - ' . get_class($e) . ' - ' . $e->getMessage(), 0);
			throw new HostMetaException(get_class($e) . ' - ' . $e->getMessage());
		}

		$url = $this->get('Link.@attributes.template', $result, '');
		$host = parse_url($url, PHP_URL_HOST);
		$protocols = [parse_url($url, PHP_URL_SCHEME)];

		return parse_url($url, PHP_URL_PATH);
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
	 * @throws UnauthorizedFediverseException
	 */
	public function retrieveAccount(string &$account): Person {
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
		if (!AP::$activityPub->isActor($actor)) {
			throw new ItemUnknownException();
		}

		if (strtolower($actor->getId()) !== strtolower($id)) {
			throw new InvalidOriginException(
				'CurlService::retrieveAccount - id: ' . $id . ' - actorId: ' . $actor->getId()
			);
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
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function retrieveObject($id): array {
		$url = parse_url($id);
		$this->mustContains(['path', 'host', 'scheme'], $url);
		$request = new Request($url['path'], Request::TYPE_GET);
		$request->setAddress($url['host']);
		$request->setProtocol($url['scheme']);

		$result = $this->retrieveJson($request);
		if (is_array($result)) {
			$result['_host'] = $request->getAddress();
		}

		return $result;
	}


	/**
	 * @param Request $request
	 *
	 * @return array
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 */
	public function retrieveJson(Request $request): array {
		$this->configService->configureRequest($request);
		$this->assignUserAgent($request);

		try {
			$result = $this->retrieveJsonOrig($request);
		} catch (RequestResultSizeException | RequestResultNotJsonException $e) {
			$this->miscService->log(
				'[!!] request: ' . json_encode($request) . ' - content-type: '
				. $request->getContentType() . ' - ' . $e->getMessage(), 1
			);
			throw $e;
		}

		return $result;
	}


	/**
	 * @param Request $request
	 *
	 * @return mixed
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 */
	public function doRequest(Request $request) {
		$this->fediverseService->authorized($request->getAddress());
		$this->configService->configureRequest($request);
		$this->assignUserAgent($request);

		return $this->doRequestOrig($request);
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
		$address = $this->configService->getSocialUrl();

		$path = $this->withEndSlash(parse_url($address, PHP_URL_PATH));
		$path .= $this->withoutBeginSlash(self::ASYNC_REQUEST_TOKEN);
		$path = str_replace('{token}', $token, $path);

		$request = new Request($path, Request::TYPE_POST);
		$request->setAddress($this->configService->getCloudHost());
		$request->setProtocol(parse_url($address, PHP_URL_SCHEME));

		try {
			$this->retrieveJson($request);
		} catch (RequestResultNotJsonException $e) {
		} catch (Exception $e) {
			$this->miscService->log(
				'Cannot initiate AsyncWithToken ' . json_encode($token) . ' (' . get_class($e)
				. ' - ' . json_encode($e) . ')', 1
			);
		}
	}


	/**
	 * @param Request $request
	 */
	protected function initRequestGet(Request $request) {
		if ($request->getType() !== Request::TYPE_GET) {
			return;
		}

		$request->addHeader(
			'Accept: application/json; profile="https://www.w3.org/ns/activitystreams"'
		);
		$request->addHeader(
			'Accept: application/ld+json; profile="https://www.w3.org/ns/activitystreams"'
		);

		$this->initRequestGetOrig($request);
	}


	/**
	 * @param resource $curl
	 * @param Request $request
	 */
	protected function initRequestPost($curl, Request $request) {
		if ($request->getType() !== Request::TYPE_POST) {
			return;
		}

		$request->addHeader(
			'Content-Type: application/ld+json; profile="https://www.w3.org/ns/activitystreams"'
		);

		$this->initRequestPostOrig($curl, $request);
	}

}

