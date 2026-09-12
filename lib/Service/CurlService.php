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
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TPathTools;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;

/**
 * Outbound HTTP for federation.
 *
 * The transport is the server's own client (`OCP\Http\Client\IClientService`),
 * so the CA bundle, the proxy configuration and the local-address checks come
 * from the server. What lives here is federation-specific: the WebFinger
 * protocol fallback, the ActivityPub content negotiation, the signed fetch and
 * its unsigned retry, the download ceiling, and the mapping onto this app's
 * request exceptions.
 *
 * A caller says what it wants sent — a method, a URL, and at most four options
 * — and gets the body back. Nothing between here and the client describes an
 * HTTP request a second time.
 */
class CurlService {
	use TArrayTools;
	use TPathTools;

	public const ASYNC_REQUEST_TOKEN = '/async/request/{token}';
	public const USER_AGENT = 'Nextcloud Social';

	private int $maxDownloadSize;

	public function __construct(
		private ConfigService $configService,
		private FediverseService $fediverseService,
		private IClientService $clientService,
		private HttpSignatureService $httpSignatureService,
		private LoggerInterface $logger,
	) {
		$this->maxDownloadSize = $this->configService->getAppValue(ConfigService::SOCIAL_MAX_SIZE) * 1048576;
	}

	/**
	 * @return array the JRD document
	 *
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

		$urls = $this->candidateUrls($protocols, $host, $path, ['resource' => 'acct:' . $account]);
		$result = $this->retrieveJsonFromFirstReachable($urls, ['json_headers' => false]);

		$this->logger->notice('webfingerAccount, request result', ['urls' => $urls]);

		// a JRD is entitled to have no subject, or one that is not an acct: URI
		$subject = $this->get('subject', $result, '');
		if (str_starts_with($subject, 'acct:')) {
			$account = substr($subject, strlen('acct:'));
		}

		return $result;
	}

	/**
	 * Follows the host's own `.well-known/host-meta` to the WebFinger endpoint
	 * it names, rewriting $host and $protocols to point at it.
	 *
	 * @param string[] $protocols
	 *
	 * @return string the WebFinger path
	 *
	 * @throws HostMetaException
	 */
	public function hostMeta(string &$host, array &$protocols): string {
		$this->logger->debug('hostMeta', ['host' => $host, 'protocols' => $protocols]);

		$urls = $this->candidateUrls($protocols, $host, '/.well-known/host-meta');
		try {
			$result = $this->retrieveJsonFromFirstReachable($urls, ['json_headers' => false]);
		} catch (Exception $e) {
			$this->logger->notice('during hostMeta', ['urls' => $urls, 'exception' => $e]);

			throw new HostMetaException(get_class($e) . ' - ' . $e->getMessage());
		}

		$url = $this->get('Link.@attributes.template', $result, '');
		if ($url === '') {
			throw new HostMetaException('Failed to get URL');
		}
		// parse_url() answers false or null for the parts it cannot find, and
		// all three of these were handed straight to callers typed for strings
		$host = parse_url($url, PHP_URL_HOST) ?: '';
		$protocols = [parse_url($url, PHP_URL_SCHEME) ?: ''];

		return parse_url($url, PHP_URL_PATH) ?: '';
	}

	/**
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
	 * Fetches an ActivityPub document by its id.
	 *
	 * The id is requested as it is written, rather than taken apart and put
	 * back together: it is the peer's own URL, and it is also what the
	 * signature covers.
	 *
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

		$parsed = parse_url($id);
		$this->mustContains(['path', 'host', 'scheme'], is_array($parsed) ? $parsed : []);

		$headers = $acceptActivityJson ? ['Accept' => 'application/activity+json'] : [];

		// An ActivityPub fetch is signed: a peer running Mastodon's
		// AUTHORIZED_FETCH or GoToSocial's secure mode answers 401 to an
		// unsigned one, which is why resolving an account there failed with
		// "user not found" and threads stopped at the first remote reply.
		$signature = $acceptActivityJson ? $this->httpSignatureService->signFetch($id) : [];

		$status = 0;
		try {
			$result = $this->retrieveJson('get', $id, ['headers' => $headers + $signature], $status);
		} catch (RequestContentException $e) {
			if ($signature === [] || !$this->refusedTheSignature($e->getCode())) {
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

			$result = $this->retrieveJson('get', $id, ['headers' => $headers], $status);
		}

		$result['_host'] = $parsed['host'];
		$result['_resultCode'] = $status;

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
	 * Fires a request at this app's own `/async/request/{token}` route so that
	 * the rows left on standby are delivered without the caller waiting.
	 *
	 * @throws SocialAppConfigException
	 */
	public function asyncWithToken(string $token): void {
		$address = $this->configService->getSocialUrl();

		$path = $this->withEndSlash((string)parse_url($address, PHP_URL_PATH));
		$path .= $this->withoutBeginSlash(self::ASYNC_REQUEST_TOKEN);
		$path = str_replace('{token}', $token, $path);

		$url = parse_url($address, PHP_URL_SCHEME) . '://' . $this->configService->getCloudHost() . $path;

		try {
			$this->retrieveJson('post', $url);
		} catch (RequestResultNotJsonException $e) {
		} catch (Exception $e) {
			$this->logger->error('Cannot initiate AsyncWithToken', ['token' => $token, 'exception' => $e]);
		}
	}

	/**
	 * Sends the request and reads the answer as JSON.
	 *
	 * @param array{headers?: array<string, string>, body?: string, timeout?: int, json_headers?: bool} $options
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function retrieveJson(string $method, string $url, array $options = [], ?int &$statusCode = null): array {
		return $this->retrieveJsonFromFirstReachable([$url], $options, $method, $statusCode);
	}

	/**
	 * The same, over a list of URLs that differ only in their scheme — see
	 * doRequestOverUrls().
	 *
	 * @param string[] $urls
	 * @param array{headers?: array<string, string>, body?: string, timeout?: int, json_headers?: bool} $options
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function retrieveJsonFromFirstReachable(
		array $urls,
		array $options = [],
		string $method = 'get',
		?int &$statusCode = null,
	): array {
		$contentType = '';
		$result = $this->doRequestOverUrls($method, $urls, $options, $contentType, $statusCode);

		// host-meta is served as XRD by most instances and as JRD by some
		if (str_starts_with($contentType, 'application/xrd')) {
			$result = (string)json_encode(simplexml_load_string($result), JSON_UNESCAPED_SLASHES);
		}

		$decoded = json_decode($result, true);
		if (is_array($decoded)) {
			return $decoded;
		}

		throw new RequestResultNotJsonException();
	}

	/**
	 * Sends the request and returns the body.
	 *
	 * @param array{headers?: array<string, string>, body?: string, timeout?: int, json_headers?: bool} $options
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function doRequest(
		string $method,
		string $url,
		array $options = [],
		?string &$contentType = null,
		?int &$statusCode = null,
	): string {
		return $this->doRequestOverUrls($method, [$url], $options, $contentType, $statusCode);
	}

	/**
	 * Sends the request to the first of $urls that answers.
	 *
	 * The list is tried in order: a connection or TLS failure falls through to
	 * the next one (an instance reachable over http only), while an answer with
	 * an error status ends the attempt right there. The URLs are expected to
	 * differ only in their scheme — the instance is asked for authorization
	 * once, by host.
	 *
	 * @param string[] $urls
	 * @param array{headers?: array<string, string>, body?: string, timeout?: int, json_headers?: bool} $options
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws UnauthorizedFediverseException
	 */
	public function doRequestOverUrls(
		string $method,
		array $urls,
		array $options = [],
		?string &$contentType = null,
		?int &$statusCode = null,
	): string {
		if ($urls === []) {
			return '';
		}

		$this->fediverseService->authorized((string)parse_url($urls[0], PHP_URL_HOST));

		$clientOptions = $this->clientOptions($method, $options);
		$client = $this->clientService->newClient();

		$networkFailure = null;
		foreach ($urls as $url) {
			try {
				return $this->send($client, $method, $url, $clientOptions, $contentType, $statusCode);
			} catch (RequestNetworkException $e) {
				$networkFailure = $e;
			}
		}

		// the list is not empty, so the loop ran and this is the last failure
		throw $networkFailure;
	}

	/**
	 * The options the server's HTTP client is handed.
	 *
	 * The guarantees that matter for a url somebody else wrote:
	 *
	 * - local addresses are refused, and the server re-checks that on every
	 *   redirect it follows (which is why `allow_redirects` is left to the
	 *   server: overriding it would drop that check). Guzzle only ever follows
	 *   a redirect to http(s), so a `file://` or `gopher://` location cannot
	 *   be reached either way.
	 * - the answer is read as a stream, so an endless body is cut off at
	 *   `max_size` rather than filling memory.
	 *
	 * @param array{headers?: array<string, string>, body?: string, timeout?: int, json_headers?: bool} $options
	 *
	 * @return array<string, mixed>
	 */
	private function clientOptions(string $method, array $options): array {
		$clientOptions = $this->configService->requestOptions(
			$options['timeout'] ?? ConfigService::DEFAULT_REQUEST_TIMEOUT
		);

		$headers = ['user-agent' => $this->userAgent()];
		$headers = $this->mergeHeaders($headers, $options['headers'] ?? []);
		if ($options['json_headers'] ?? true) {
			$headers = $this->mergeHeaders($headers, $this->configService->activityPubHeaders($method));
		}

		$clientOptions['headers'] = $headers;
		// the status code belongs to the caller, not to an exception
		$clientOptions['http_errors'] = false;
		$clientOptions['stream'] = true;

		if (($options['body'] ?? '') !== '' && strtolower($method) !== 'get') {
			$clientOptions['body'] = $options['body'];
		}

		return $clientOptions;
	}

	/**
	 * Adds $extra to $headers, appending to a header that is already there
	 * rather than replacing it — a request that asks for two media types asks
	 * for both of them.
	 *
	 * @param array<string, string> $headers
	 * @param array<string, string> $extra
	 *
	 * @return array<string, string>
	 */
	private function mergeHeaders(array $headers, array $extra): array {
		foreach ($extra as $key => $value) {
			$headers[$key] = isset($headers[$key]) ? $headers[$key] . ', ' . $value : $value;
		}

		return $headers;
	}

	public function userAgent(): string {
		return self::USER_AGENT . ' ' . $this->configService->getAppValue('installed_version');
	}

	/**
	 * @param array<string, mixed> $clientOptions
	 *
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 */
	private function send(
		IClient $client,
		string $method,
		string $url,
		array $clientOptions,
		?string &$contentType,
		?int &$statusCode,
	): string {
		$host = (string)parse_url($url, PHP_URL_HOST);
		if (!($clientOptions['nextcloud']['allow_local_address'] ?? false) && RemoteAddress::isLocalHost($host)) {
			throw new RequestServerException('host resolves to a local address: ' . $host);
		}

		try {
			$response = $client->request(strtolower($method), $url, $clientOptions);
		} catch (Exception $e) {
			throw new RequestNetworkException($e->getMessage() . ' - ' . $url, $e->getCode());
		}

		$statusCode = $response->getStatusCode();
		$contentType = $response->getHeader('Content-Type');

		$this->logger->debug('[>>] ' . $url . ' result [' . $statusCode . ']');

		$body = $this->body($response);
		if ($statusCode >= 300) {
			throw new RequestContentException($url, $statusCode);
		}

		return $body;
	}

	/**
	 * `scheme://host/path?query` for every scheme in $protocols, which is how
	 * an instance reachable over http only is still found.
	 *
	 * @param string[] $protocols
	 * @param array<string, string> $params
	 *
	 * @return string[]
	 */
	private function candidateUrls(array $protocols, string $host, string $path, array $params = []): array {
		$query = ($params === []) ? '' : '?' . http_build_query($params);

		return array_map(
			static fn (string $protocol): string => $protocol . '://' . $host . $path . $query,
			$protocols
		);
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
