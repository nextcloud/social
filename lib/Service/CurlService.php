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

use OCA\Social\Tools\Exceptions\ArrayNotFoundException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use OCA\Social\Tools\Traits\TNCRequest;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TPathTools;
use OCA\Social\Tools\Traits\TNCSetup;
use OCA\Social\Tools\Traits\TNCLogger;
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
	use TNCSetup;
	use TNCLogger;
	use TPathTools;
	use TNCRequest {
		retrieveJson as retrieveJsonOrig;
		doRequest as doRequestOrig;
	}

	public const ASYNC_REQUEST_TOKEN = '/async/request/{token}';
	public const USER_AGENT = 'Nextcloud Social';

	private ConfigService $configService;
	private FediverseService $fediverseService;
	private MiscService $miscService;


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
		$this->setup('app', 'social');
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
		$this->debug('webfingerAccount', ['account' => $account]);
		$account = $this->withoutBeginAt($account);

		// we consider an account is like an email
		if (!filter_var($account, FILTER_VALIDATE_EMAIL)) {
			throw new InvalidResourceException('account format is not valid');
		}

		$exploded = explode('@', $account);

		if (count($exploded) < 2) {
			throw new InvalidResourceException();
		}
		[$username, $host] = $exploded;

		$protocols = ['https', 'http'];
		try {
			$path = $this->hostMeta($host, $protocols);
		} catch (HostMetaException $e) {
			$path = '/.well-known/webfinger';
		}

		$request = new NCRequest($path);
		$request->addParam('resource', 'acct:' . $account);
		$request->setHost($host);
		$request->setProtocols($protocols);
		$result = $this->retrieveJson($request);

		$this->notice('webfingerAccount, request result', false, ['request' => $request]);

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
		$request = new NCRequest('/.well-known/host-meta');
		$request->setHost($host);
		$request->setProtocols($protocols);

		$this->debug('hostMeta', ['host' => $host, 'protocols' => $protocols]);

		try {
			$result = $this->retrieveJson($request);
		} catch (Exception $e) {
			$this->exception($e, self::$NOTICE, ['request' => $request]);

			throw new HostMetaException(get_class($e) . ' - ' . $e->getMessage());
		}

		$url = $this->get('Link.@attributes.template', $result, '');
		if ($url === '') {
			throw new HostMetaException('Failed to get URL');
		}
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
		$this->debug('retrieveAccount', ['account' => $account]);
		$result = $this->webfingerAccount($account);

		try {
			$link = $this->extractArray('rel', 'self', $this->getArray('links', $result));
		} catch (ArrayNotFoundException $e) {
			throw new RetrieveAccountFormatException();
		}

		$id = $this->get('href', $link, '');
		$data = $this->retrieveObject($id);

		$this->debug('retrieveAccount, details', ['link' => $link, 'data' => $data, 'account' => $account]);

		/** @var Person $actor */
		$actor = AP::$activityPub->getItemFromData($data);
		if (!AP::$activityPub->isActor($actor)) {
			throw new ItemUnknownException(json_encode($actor) . ' is not an Actor');
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
		$this->debug('retrieveObject', ['id' => $id]);
		$url = parse_url($id);
		$this->mustContains(['path', 'host', 'scheme'], $url);
		$request = new NCRequest($url['path'], Request::TYPE_GET);
		$request->setHost($url['host']);
		$request->setProtocol($url['scheme']);

		$this->debug('retrieveObject', ['request' => $request]);

		$result = $this->retrieveJson($request);
		$this->notice('retrieveObject, request result', false, ['request' => $request]);

		if (is_array($result)) {
			$result['_host'] = $request->getHost();
		}

		return $result;
	}


	/**
	 * @param NCRequest $request
	 *
	 * @return array
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 */
	public function retrieveJson(NCRequest $request): array {
		try {
			return $this->retrieveJsonOrig($request);
		} catch (RequestNetworkException | RequestContentException $e) {
			$this->exception($e, self::$NOTICE, ['request' => $request]);
			throw $e;
		}
	}


	/**
	 * @param NCRequest $request
	 *
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 */
	// migration ?
	public function doRequest(NCRequest $request): string {
		$this->fediverseService->authorized($request->getAddress());
		$this->configService->configureRequest($request);
		$this->assignUserAgent($request);

		return $this->doRequestOrig($request);
	}


	/**
	 * @param NCRequest $request
	 */
	public function assignUserAgent(NCRequest $request): void {
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

		$request = new NCRequest($path, Request::TYPE_POST);
		$request->setHost($this->configService->getCloudHost());
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
}
