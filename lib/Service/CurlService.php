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
use OCA\Social\Tools\Exceptions\ArrayNotFoundException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TPathTools;
use Psr\Log\LoggerInterface;

class CurlService {
	use TArrayTools;
	use TPathTools;

	public const ASYNC_REQUEST_TOKEN = '/async/request/{token}';
	public const USER_AGENT = 'Nextcloud Social';

	private ConfigService $configService;
	private FediverseService $fediverseService;
	private LoggerInterface $logger;

	private int $maxDownloadSize;
	private bool $maxDownloadSizeReached = false;

	/**
	 * CurlService constructor.
	 *
	 * @param ConfigService $configService
	 * @param FediverseService $fediverseService
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		ConfigService $configService,
		FediverseService $fediverseService,
		LoggerInterface $logger
	) {
		$this->configService = $configService;
		$this->fediverseService = $fediverseService;
		$this->logger = $logger;
		$this->maxDownloadSize = $this->configService->getAppValue(ConfigService::SOCIAL_MAX_SIZE) * 1048576;
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
		$this->logger->debug('webfingerAccount', ['account' => $account]);
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

		$this->logger->notice('webfingerAccount, request result', ['request' => $request]);

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

		$this->logger->debug('hostMeta', ['host' => $host, 'protocols' => $protocols]);

		try {
			$result = $this->retrieveJson($request);
		} catch (Exception $e) {
			$this->logger->notice('during hostMeta', ['request' => $request, 'exception' => $e]);

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
		$this->logger->debug('retrieveAccount', ['account' => $account]);
		$result = $this->webfingerAccount($account);

		try {
			$link = $this->extractArray('rel', 'self', $this->getArray('links', $result));
		} catch (ArrayNotFoundException $e) {
			throw new RetrieveAccountFormatException();
		}

		$id = $this->get('href', $link, '');
		$data = $this->retrieveObject($id);

		$this->logger->debug(
			'retrieveAccount, details', ['link' => $link, 'data' => $data, 'account' => $account]
		);

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
		$this->logger->debug('retrieveObject', ['id' => $id]);
		$url = parse_url($id);
		$this->mustContains(['path', 'host', 'scheme'], $url);
		$request = new NCRequest($url['path'], Request::TYPE_GET);
		$request->setHost($url['host']);
		$request->setProtocol($url['scheme']);

		$this->logger->debug('retrieveObject', ['request' => $request]);

		$result = $this->retrieveJson($request);
		$this->logger->notice('retrieveObject, request result', ['request' => $request]);

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
			$this->logger->notice('during retrieveJson', ['request' => $request, 'exception' => $e]);

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
			$this->logger->error('Cannot initiate AsyncWithToken', ['token' => $token, 'exception' => $e]);
		}
	}


	/**
	 * @param NCRequest $request
	 *
	 * @return array
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 */
	public function retrieveJsonOrig(NCRequest $request): array {
		$result = $this->doRequest($request);

		if (strpos($request->getContentType(), 'application/xrd') === 0) {
			$xml = simplexml_load_string($result);
			$result = json_encode($xml, JSON_UNESCAPED_SLASHES);
		}

		$result = json_decode((string)$result, true);
		if (is_array($result)) {
			return $result;
		}

		throw new RequestResultNotJsonException();
	}


	/**
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 */
	public function doRequestOrig(Request $request): string {
		$this->maxDownloadSizeReached = false;

		$ignoreProtocolOnErrors = [7];
		$result = '';
		foreach ($request->getProtocols() as $protocol) {
			$request->setUsedProtocol($protocol);
			$curl = $this->initRequest($request);

			$result = curl_exec($curl);
			$this->logger->debug(
				'[>>] ' . json_encode($request)
				. '   result [' . curl_getinfo($curl, CURLINFO_HTTP_CODE) . ']: ' . json_encode($result)
			);

			if (in_array(curl_errno($curl), $ignoreProtocolOnErrors)) {
				continue;
			}

			if ($this->maxDownloadSizeReached === true) {
				throw new RequestResultSizeException();
			}

			$this->parseRequestResult($curl, $request);
			break;
		}

		if ($result === false) {
			return '';
		}

		return (string)$result;
	}


	/**
	 * @param Request $request
	 *
	 * @return resource
	 */
	private function initRequest(Request $request) {
		$curl = $this->generateCurlRequest($request);
		$this->initRequestHeaders($curl, $request);

		curl_setopt($curl, CURLOPT_USERAGENT, $request->getUserAgent());
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $request->getTimeout());
		curl_setopt($curl, CURLOPT_TIMEOUT, $request->getTimeout());

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_BINARYTRANSFER, $request->isBinary());

		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $request->isVerifyPeer());
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, $request->isFollowLocation());

		curl_setopt($curl, CURLOPT_BUFFERSIZE, 128);
		curl_setopt($curl, CURLOPT_NOPROGRESS, false);
		curl_setopt($curl, CURLOPT_PROGRESSFUNCTION,
			/**
			 * @param $downloadSize
			 * @param int $downloaded
			 * @param $uploadSize
			 * @param int $uploaded
			 *
			 * @return int
			 */
			function ($downloadSize, int $downloaded, $uploadSize, int $uploaded) {
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
		$url = $request->getUsedProtocol() . '://' . $request->getHost() . $request->getParsedUrl();
		if ($request->getType() !== Request::TYPE_GET) {
			$curl = curl_init($url);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $request->getDataBody());

			return $curl;
		}

		$curl = curl_init($url . $request->getQueryString());
		switch ($request->getType()) {
			case Request::TYPE_POST:
				curl_setopt($curl, CURLOPT_POST, true);
				break;
			case Request::TYPE_PUT:
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
				break;
			case Request::TYPE_DELETE:
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
				break;
		}

		return $curl;
	}


	/**
	 * @param Request $request
	 */
	private function initRequestGet(Request $request) {
		if ($request->getType() !== Request::TYPE_GET) {
			return;
		}
	}

	/**
	 * @param resource $curl
	 * @param Request $request
	 */
	private function initRequestHeaders($curl, Request $request) {
		$headers = [];
		foreach ($request->getHeaders() as $name => $value) {
			$headers[] = $name . ': ' . $value;
		}

		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
	}


	/**
	 * @param resource $curl
	 * @param Request $request
	 *
	 * @throws RequestContentException
	 * @throws RequestServerException
	 * @throws RequestNetworkException
	 */
	private function parseRequestResult($curl, Request $request): void {
		$this->parseRequestResultCurl($curl, $request);

		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
		$request->setContentType((!is_string($contentType)) ? '' : $contentType);
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
	private function parseRequestResultCode301(int $code, Request $request) {
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
