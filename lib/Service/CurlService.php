<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
use OCA\Social\Security\RemoteAddress;
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
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
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

	public function __construct(
		ConfigService $configService,
		FediverseService $fediverseService,
		private IClientService $clientService,
		private HttpSignatureService $httpSignatureService,
		LoggerInterface $logger,
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
		// if (!filter_var($account, FILTER_VALIDATE_EMAIL)) {
		// 	throw new InvalidResourceException('account format is not valid');
		// }

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
		$request->setClientOptions(['ignoreJsonHeaders' => true]);
		$request->setProtocols($protocols);
		$result = $this->retrieveJson($request);

		$this->logger->notice('webfingerAccount, request result', ['request' => $request]);

		$subject = $this->get('subject', $result, '');
		[$type, $temp] = explode(':', $subject, 2);
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
		$request->setClientOptions(['ignoreJsonHeaders' => true]);

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
		$actor = AP::instance()->getItemFromData($data);
		if (!AP::instance()->isActor($actor)) {
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
	public function retrieveObject(string $id, bool $acceptActivityJson = true): array {
		$this->logger->debug('retrieveObject id=' . $id);

		$request = $this->objectRequest($id, $acceptActivityJson);

		// An ActivityPub fetch is signed: a peer running Mastodon's
		// AUTHORIZED_FETCH or GoToSocial's secure mode answers 401 to an
		// unsigned one, which is why resolving an account there failed with
		// "user not found" and threads stopped at the first remote reply.
		$signed = $acceptActivityJson && $this->httpSignatureService->signFetch($request);

		try {
			$result = $this->retrieveJson($request);
		} catch (RequestContentException $e) {
			if (!$signed || !$this->refusedTheSignature($e->getCode())) {
				throw $e;
			}

			// A signature is an addition to a request that used to go out
			// without one, and a peer is entitled not to expect it — an
			// unresolvable keyId, a stricter reverse proxy. One unsigned retry
			// keeps those reachable; it costs an extra request only where the
			// first one had already failed.
			$this->logger->debug('a signed fetch was refused, retrying unsigned', [
				'id' => $id, 'status' => $e->getCode(),
			]);

			$request = $this->objectRequest($id, $acceptActivityJson);
			$result = $this->retrieveJson($request);
		}

		$result['_host'] = $request->getHost();
		$result['_resultCode'] = $request->getResultCode();

		return $result;
	}

	/**
	 * Whether a status is one a peer would answer to a signature it did not
	 * want. A 4xx that is about anything else (404, 410) is the answer, not a
	 * reason to ask again.
	 */
	private function refusedTheSignature(int $status): bool {
		return $status === Http::STATUS_UNAUTHORIZED || $status === Http::STATUS_FORBIDDEN;
	}

	/**
	 * @throws MalformedArrayException
	 */
	private function objectRequest(string $id, bool $acceptActivityJson): NCRequest {
		$url = parse_url($id);
		$this->mustContains(['path', 'host', 'scheme'], $url);
		$request = new NCRequest($url['path'], Request::TYPE_GET);
		$request->setHost($url['host']);
		$request->setProtocol($url['scheme']);
		if (isset($url['query']) && $url['query'] !== '') {
			parse_str($url['query'], $queryParams);
			foreach ($queryParams as $k => $v) {
				$request->addParam($k, $v);
			}
		}
		if ($acceptActivityJson) {
			$request->addHeader('Accept', 'application/activity+json');
		}

		return $request;
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
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function retrieveJson(NCRequest $request): array {
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
	 * Sends the request and returns the body. The protocol list is tried in
	 * order: a connection or TLS failure falls through to the next one (an
	 * instance reachable over http only), while an answer with an error status
	 * ends the attempt right there.
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 */
	public function doRequestOrig(NCRequest $request): string {
		$client = $this->clientService->newClient();

		$networkFailure = null;
		foreach ($request->getProtocols() as $protocol) {
			$request->setUsedProtocol($protocol);

			try {
				return $this->send($client, $request);
			} catch (RequestNetworkException $e) {
				$networkFailure = $e;
			}
		}

		if ($networkFailure !== null) {
			throw $networkFailure;
		}

		return '';
	}

	/**
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 */
	private function send(IClient $client, NCRequest $request): string {
		if (!$request->isLocalAddressAllowed() && RemoteAddress::isLocalHost($request->getHost())) {
			throw new RequestServerException('host resolves to a local address: ' . $request->getHost());
		}

		$url = $this->url($request);
		try {
			$response = $client->request(Request::method($request->getType()), $url, $this->requestOptions($request));
		} catch (Exception $e) {
			throw new RequestNetworkException(
				$e->getMessage() . ' - ' . json_encode($request, JSON_UNESCAPED_SLASHES), $e->getCode()
			);
		}

		$request->setResultCode($response->getStatusCode());
		$request->setContentType($response->getHeader('Content-Type'));

		$this->logger->debug('[>>] ' . $url . ' result [' . $response->getStatusCode() . ']');

		$body = $this->body($response);
		if ($request->getResultCode() >= 300) {
			throw new RequestContentException(json_encode($request), $request->getResultCode());
		}

		return $body;
	}

	private function url(Request $request): string {
		$url = $request->getUsedProtocol() . '://' . $request->getHost() . $request->getParsedUrl();
		if ($request->getType() === Request::TYPE_GET) {
			$url .= $request->getQueryString();
		}

		return $url;
	}

	/**
	 * The guarantees that matter for a url somebody else wrote:
	 *
	 * - local addresses are refused, and the server re-checks that on every
	 *   redirect it follows (which is why `allow_redirects` is left to the
	 *   server: overriding it would drop that check). Guzzle only ever follows
	 *   a redirect to http(s), so a `file://` or `gopher://` location cannot
	 *   be reached either way.
	 * - the answer is read as a stream, so an endless body is cut off at
	 *   `max_size` rather than filling memory.
	 */
	private function requestOptions(NCRequest $request): array {
		$options = [
			'headers' => $request->getHeaders(),
			'timeout' => $request->getTimeout(),
			'connect_timeout' => $request->getConnectTimeout() ?: $request->getTimeout(),
			// the status code belongs to the caller, not to an exception
			'http_errors' => false,
			'stream' => true,
			'nextcloud' => ['allow_local_address' => $request->isLocalAddressAllowed()],
		];

		if (!$request->isFollowLocation()) {
			$options['allow_redirects'] = false;
		}

		if (!$request->isVerifyPeer()) {
			$options['verify'] = false;
		}

		if ($request->getType() !== Request::TYPE_GET && $request->getDataBody() !== '') {
			$options['body'] = $request->getDataBody();
		}

		return $options;
	}

	/**
	 * @throws RequestResultSizeException
	 */
	private function body(IResponse $response): string {
		$stream = $response->getBody();
		if (!is_resource($stream)) {
			// a client that does not stream (a test double, say) hands over
			// the whole body at once
			$body = (string)$stream;
			if (strlen($body) > $this->maxDownloadSize) {
				throw new RequestResultSizeException();
			}

			return $body;
		}

		try {
			$body = (string)stream_get_contents($stream, $this->maxDownloadSize + 1);
		} finally {
			fclose($stream);
		}

		if (strlen($body) > $this->maxDownloadSize) {
			throw new RequestResultSizeException();
		}

		return $body;
	}
}
