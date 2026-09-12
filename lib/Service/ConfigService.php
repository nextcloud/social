<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TPathTools;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Class ConfigService
 *
 * @package OCA\Social\Service
 */
class ConfigService {
	use TPathTools;
	use TArrayTools;

	public const CLOUD_URL = 'cloud_url';
	public const SOCIAL_URL = 'social_url';
	public const SOCIAL_ADDRESS = 'social_address';

	public const SOCIAL_SERVICE = 'service';
	public const SOCIAL_MAX_SIZE = 'max_size';
	public const SOCIAL_ACCESS_TYPE = 'access_type';
	public const SOCIAL_ACCESS_LIST = 'access_list';

	public const SOCIAL_SELF_SIGNED = 'allow_self_signed';

	/** incoming inbox requests allowed per origin host per minute; 0 disables */
	public const SOCIAL_INBOX_THROTTLE = 'inbox_throttle';

	/** days to keep remote statuses nobody local cares about; 0 disables */
	public const SOCIAL_RETENTION_DAYS = 'retention_days';

	/**
	 * Instances whose accounts are silenced rather than blocked: out of the
	 * public and global timelines, still readable by whoever follows them.
	 *
	 * The middle tier a domain block did not have. Without it the only answer
	 * to an instance that is a nuisance rather than a menace was to cut it off
	 * entirely, which also cuts off the local users who deliberately follow
	 * somebody there.
	 */
	public const SOCIAL_SILENCED_LIST = 'silenced_list';

	/**
	 * Whether an ActivityPub GET must be signed to be answered.
	 *
	 * Mastodon's secure mode. Off by default, and deliberately: turning it on
	 * makes this instance invisible to every peer that does not sign its
	 * fetches, which is a decision about who an instance federates with rather
	 * than something to arrive at by upgrading.
	 */
	public const SOCIAL_SECURE_MODE = 'secure_mode';

	/**
	 * Whether `/api/v1/instance/domain_blocks` publishes the deny list.
	 *
	 * Off by default. Mastodon publishes the list so somebody choosing a
	 * server can see who it will not talk to; whether *this* server wants that
	 * read by anybody is a disclosure decision its admin makes, not a default.
	 */
	public const SOCIAL_PUBLISH_BLOCKS = 'publish_blocks';

	/**
	 * How far the closed-poll sweep has got, as a timestamp.
	 *
	 * A poll closes by its end time passing, so nothing happens at the moment
	 * it does and something has to look. This is where that look left off.
	 */
	public const SOCIAL_POLLS_SWEPT = 'polls_swept';

	/** The long form of what this instance is, for `instance/extended_description`. */
	public const SOCIAL_EXTENDED_DESCRIPTION = 'extended_description';

	public array $defaults = [
		self::CLOUD_URL => '',
		self::SOCIAL_URL => '',
		self::SOCIAL_ADDRESS => '',
		self::SOCIAL_SERVICE => 1,
		self::SOCIAL_MAX_SIZE => 10,
		self::SOCIAL_ACCESS_TYPE => 'all_but',
		self::SOCIAL_ACCESS_LIST => '[]',
		self::SOCIAL_SELF_SIGNED => '0',
		self::SOCIAL_INBOX_THROTTLE => '300',
		self::SOCIAL_RETENTION_DAYS => '0',
		self::SOCIAL_SILENCED_LIST => '[]',
		self::SOCIAL_SECURE_MODE => '0',
		self::SOCIAL_PUBLISH_BLOCKS => '0',
		self::SOCIAL_EXTENDED_DESCRIPTION => '',
		self::SOCIAL_POLLS_SWEPT => '0'
	];

	public array $accessTypeList = [
		'BLACKLIST' => 'all_but',
		'WHITELIST' => 'none_but'
	];

	private ?string $userId = null;

	/** Seconds a federation request may take when nobody asks for anything else. */
	public const DEFAULT_REQUEST_TIMEOUT = 10;

	/** Seconds; 0 leaves each request its own default. See withRequestTimeout(). */
	private int $requestTimeout = 0;

	/** Seconds allowed for reaching the peer alone; 0 shares $requestTimeout. */
	private int $requestConnectTimeout = 0;

	public function __construct(
		?string $userId,
		private IAppConfig $appConfig,
		private IUserConfig $userConfig,
		private IConfig $config,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
		private MiscService $miscService,
	) {
		$this->userId = $userId;
	}

	/**
	 * @return array
	 */
	public function getConfig(): array {
		$keys = array_keys($this->defaults);
		$data = [];

		foreach ($keys as $k) {
			$data[$k] = $this->getAppValue($k);
		}

		return $data;
	}

	/**
	 * /**
	 * Get a value by key
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	/**
	 * Whether a user's block is also sent to the blocked account's server as a
	 * Block activity (Mastodon behaviour, the default). Disable to keep blocks
	 * strictly local:  occ config:app:set social federate_blocks --value 0
	 */
	public function isBlockFederationEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'federate_blocks', '1') !== '0';
	}

	public function getAppValue($key) {
		$defaultValue = null;
		if (array_key_exists($key, $this->defaults)) {
			$defaultValue = $this->defaults[$key];
		}

		return $this->appConfig->getValueString(Application::APP_ID, $key, (string)$defaultValue);
	}

	/**
	 * Get a value by key
	 *
	 * @param string $key
	 *
	 * @return int
	 */
	public function getAppValueInt(string $key): int {
		$defaultValue = null;
		if (array_key_exists($key, $this->defaults)) {
			$defaultValue = $this->defaults[$key];
		}

		return (int)$this->appConfig->getValueString(Application::APP_ID, $key, (string)$defaultValue);
	}

	/**
	 * Set a value by key
	 *
	 * @param string $key
	 * @param string $value
	 *
	 * @return void
	 */
	public function setAppValue($key, $value) {
		$this->appConfig->setValueString(Application::APP_ID, $key, (string)$value);
	}

	/**
	 * remove a key
	 *
	 * @param string $key
	 */
	public function deleteAppValue($key): void {
		$this->appConfig->deleteKey(Application::APP_ID, $key);
	}

	/**
	 * Get a user value by key
	 *
	 * @param string $key
	 * @param string $userId
	 * @param string $app
	 *
	 * @return string
	 */
	public function getUserValue(string $key, string $userId = '', string $app = '') {
		if ($userId === '') {
			$userId = $this->userId;
		}

		$defaultValue = '';
		if ($app === '') {
			$app = Application::APP_ID;
			if (array_key_exists($key, $this->defaults)) {
				$defaultValue = $this->defaults[$key];
			}
		}

		return $this->userConfig->getValueString($userId, $app, $key, (string)$defaultValue);
	}

	/**
	 * Set a user value by key
	 *
	 * @param string $key
	 * @param string $value
	 */
	public function setUserValue($key, $value): void {
		$this->userConfig->setValueString($this->userId, Application::APP_ID, $key, (string)$value);
	}

	/**
	 * Get a user value by key and user
	 *
	 * @param string $userId
	 * @param string $key
	 *
	 * @return string
	 */
	public function getValueForUser($userId, $key) {
		return $this->userConfig->getValueString($userId, Application::APP_ID, $key);
	}

	/**
	 * Set a user value by key
	 *
	 * @param string $userId
	 * @param string $key
	 * @param string $value
	 *
	 */
	public function setValueForUser($userId, $key, $value): void {
		$this->userConfig->setValueString($userId, Application::APP_ID, $key, (string)$value);
	}

	/**
	 * @param string $key
	 * @param string $value
	 */
	public function setCoreValue(string $key, string $value) {
		$this->appConfig->setValueString('core', $key, $value);
	}

	/**
	 * @param string $key
	 *
	 * @return string
	 */
	public function getCoreValue(string $key): string {
		return $this->appConfig->getValueString('core', $key, '');
	}

	/**
	 * @param string $key
	 */
	public function unsetCoreValue(string $key) {
		$this->appConfig->deleteKey('core', $key);
	}

	/**
	 *
	 */
	public function unsetAppConfig() {
		$this->appConfig->deleteApp(Application::APP_ID);
	}

	/**
	 * @param $key
	 *
	 * @return mixed
	 *
	 * @psalm-param string $key
	 */
	public function getSystemValue(string $key) {
		return $this->config->getSystemValue($key, '');
	}

	/**
	 * Whether outbound requests may reach the instance's own network.
	 *
	 * The standard Nextcloud setting, off by default; the single switch every
	 * outbound path consults before contacting a local address.
	 */
	public function isLocalNetworkAllowed(): bool {
		return $this->config->getSystemValueBool('allow_local_remote_servers', false);
	}

	/**
	 * getCloudHost - cloud.example.com
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getCloudHost(): string {
		$url = $this->getCloudUrl();
		$host = parse_url($url, PHP_URL_HOST);

		// parse_url() answers null for a URL with no host and false for one it
		// cannot parse at all; the declared string return then made either a
		// TypeError, thrown from whichever caller happened to be first.
		if (!is_string($host) || $host === '') {
			throw new SocialAppConfigException(
				'the configured cloud address has no host: ' . $url
			);
		}

		return $host;
	}

	/**
	 * getCloudUrl - https://cloud.example.com/index.php
	 *             - https://cloud.example.com
	 *
	 * @param bool $noPhp
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getCloudUrl(bool $noPhp = false) {
		$address = $this->getAppValue(self::CLOUD_URL);
		if ($address === '') {
			throw new SocialAppConfigException();
		}

		if ($noPhp) {
			$pos = strpos($address, '/index.php');
			if ($pos) {
				$address = substr($address, 0, $pos);
			}
		}

		return $this->withoutEndSlash($address, false, false);
	}

	/**
	 * @param string $cloudAddress
	 */
	public function setCloudUrl(string $cloudAddress) {
		if (parse_url($cloudAddress, PHP_URL_SCHEME) === null) {
			$cloudAddress = 'http://' . $cloudAddress;
		}

		$this->setAppValue(self::CLOUD_URL, $cloudAddress);
	}

	/**
	 * getSocialAddress - example.com
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getSocialAddress(): string {
		$address = $this->getAppValue(self::SOCIAL_ADDRESS);

		if ($address === '') {
			return $this->getCloudHost();
		}

		return $address;
	}

	/**
	 * @param string $address
	 */
	public function setSocialAddress(string $address) {
		$this->setAppValue(self::SOCIAL_ADDRESS, $address);
	}

	/**
	 * getSocialUrl - https://cloud.example.com/apps/social/
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function getSocialUrl(): string {
		$socialUrl = $this->getAppValue(self::SOCIAL_URL);
		if ($socialUrl === '') {
			throw new SocialAppConfigException();
		}

		return $socialUrl;
	}

	/**
	 * @param string $url
	 *
	 * @throws SocialAppConfigException
	 */
	public function setSocialUrl(string $url = '') {
		if ($url === '') {
			$url = $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute('social.Navigation.navigate')
			);
		}

		if (parse_url($url, PHP_URL_SCHEME) === null) {
			$url = 'http://' . $url;
		}

		$this->setAppValue(self::SOCIAL_URL, $url);
	}

	/**
	 * @param string $path
	 * @param bool $generateId
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 */
	public function generateId(string $path = '', $generateId = true): string {
		$path = $this->withoutBeginSlash($this->withEndSlash($path));

		$id = $this->getSocialUrl() . $path;
		if ($generateId === true) {
			// The random half comes from the system's random source rather than
			// from crc32(uniqid()): uniqid() is the clock, and its checksum left
			// roughly a million candidates per second to enumerate offline. Same
			// shape as before — the timestamp followed by ten digits — so ids
			// already stored stay valid.
			$id .= time() . str_pad((string)random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
		}

		return $id;
	}

	/**
	 * Runs $action with every federation request it makes bounded to $timeout
	 * seconds instead of the default.
	 *
	 * Work that happens before a caller is authenticated must not be able to
	 * hold a PHP worker for the full federation timeout — a few dozen
	 * concurrent requests naming a host that never answers would otherwise take
	 * the whole instance down, not just this app. The override lasts exactly as
	 * long as the call.
	 *
	 * @return mixed whatever $action returns
	 */
	public function withRequestTimeout(int $timeout, callable $action, int $connectTimeout = 0) {
		$previous = $this->requestTimeout;
		$previousConnect = $this->requestConnectTimeout;
		$this->requestTimeout = max(1, $timeout);
		$this->requestConnectTimeout = max(0, $connectTimeout);
		try {
			return $action();
		} finally {
			$this->requestTimeout = $previous;
			$this->requestConnectTimeout = $previousConnect;
		}
	}

	/**
	 * The transport options every federation request goes out with, as
	 * `OCP\Http\Client\IClient` takes them: how long it may take, whether the
	 * peer's certificate has to check out, and whether it may be on this
	 * instance's own network.
	 *
	 * Federation reaches arbitrary public hosts, but must not be pointed at the
	 * instance's own network. Local targets are permitted only where the admin
	 * has opted in through the standard Nextcloud setting (default off).
	 *
	 * @param int $timeout what the caller asks for; a bounded call
	 *                     (withRequestTimeout()) overrides it
	 *
	 * @return array<string, mixed>
	 */
	public function requestOptions(int $timeout = self::DEFAULT_REQUEST_TIMEOUT): array {
		if ($this->requestTimeout > 0) {
			$timeout = $this->requestTimeout;
		}

		$options = [
			'timeout' => $timeout,
			// reaching the peer has no budget of its own unless one was asked
			// for, and may then use the whole read timeout
			'connect_timeout' => ($this->requestConnectTimeout > 0) ? $this->requestConnectTimeout : $timeout,
			'nextcloud' => ['allow_local_address' => $this->isLocalNetworkAllowed()],
		];

		if ($this->getAppValue(self::SOCIAL_SELF_SIGNED) === '1') {
			$options['verify'] = false;
		}

		return $options;
	}

	/**
	 * The ActivityPub content negotiation a federation request carries: what
	 * this app is willing to read back, and what it is sending.
	 *
	 * WebFinger, host-meta and cached media are not ActivityPub and ask for
	 * none of it — those callers pass `json_headers: false`.
	 *
	 * @return array<string, string>
	 */
	public function activityPubHeaders(string $method): array {
		return match (strtolower($method)) {
			'get' => [
				'Accept' => 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
			],
			'post' => ['Content-Type' => 'application/activity+json'],
			default => [],
		};
	}
}
