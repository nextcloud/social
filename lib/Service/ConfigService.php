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
	/** The ceiling for a video, which is not the ceiling for a picture. */
	public const SOCIAL_MAX_VIDEO_SIZE = 'max_video_size';
	/** Whether a post that is a video is federated as a `Video` object. */
	public const SOCIAL_PUBLISH_VIDEO = 'publish_video_objects';
	public const SOCIAL_ACCESS_TYPE = 'access_type';
	public const SOCIAL_ACCESS_LIST = 'access_list';

	public const SOCIAL_SELF_SIGNED = 'allow_self_signed';

	/** incoming inbox requests allowed per origin host per minute; 0 disables */
	public const SOCIAL_INBOX_THROTTLE = 'inbox_throttle';

	/** days to keep remote statuses nobody local cares about; 0 disables */
	public const SOCIAL_RETENTION_DAYS = 'retention_days';

	/**
	 * Days to keep a cached remote actor nobody here refers to any more —
	 * not followed, not following, no post stored, no relation pending — before
	 * the cache cron evicts it with its avatar; 0 disables the sweep.
	 */
	public const SOCIAL_CACHE_ACTOR_DAYS = 'cache_actor_days';

	/**
	 * Which of `Cron\Cache`'s steps the next pass begins with — bookkeeping,
	 * not a setting. Written by the job when it runs out of its budget, so the
	 * steps at the end of the list are not the ones that never run.
	 */
	public const SOCIAL_CACHE_CRON_START = 'cache_cron_start';

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

	/**
	 * Whether the first post of an account that has published nothing here yet
	 * is held for a moderator.
	 *
	 * **Off by default**, and it was on until it was clear what that meant: an
	 * account on this server is a Nextcloud account that an administrator
	 * provisioned, and holding its first post made every new person's first
	 * five minutes look broken — the composer closed, the post did not appear,
	 * and the only place it existed was a panel their administrator had not
	 * opened yet. The end-to-end suite failed on exactly that, twice per run,
	 * on a clean install. An instance that hands out accounts to strangers
	 * turns it on; the spam rules (`autospam`) stay on for everybody.
	 */
	public const SOCIAL_REVIEW_FIRST_POST = 'review_first_post';

	/**
	 * Whether a post that trips the spam rules waits for a moderator.
	 *
	 * On by default, and a very short list of rules — see `PostReviewService`.
	 * What this is not is a filter: nothing is ever refused by it, only put in
	 * front of a person.
	 */
	public const SOCIAL_AUTOSPAM = 'autospam';

	/**
	 * Whether the composer offers the 881 animated emoji.
	 *
	 * On by default. What ships is the list, not the pictures — see
	 * `GifPackService` for why, and for what the instance fetches and when.
	 * An administrator who wants nothing fetched sets this to 0 and is left
	 * with whatever they added with `occ social:gif add`.
	 */
	public const SOCIAL_GIF_PACK = 'gif_pack';

	/**
	 * How many posts an account must have published here before its posts stop
	 * being held.
	 *
	 * `1` — hold the first one only — is the default and is what "first-post
	 * review" means. An instance that has had trouble can ask for more, and an
	 * account that has had that many posts approved is one a person has
	 * already looked at that many times.
	 */
	public const SOCIAL_REVIEW_POSTS = 'review_posts';

	/**
	 * The longest edge a stored picture may have, in pixels; 0 keeps every
	 * upload exactly as it arrived.
	 *
	 * Off by default, because this app's promise has been that nothing loses a
	 * generation of quality: the metadata is stripped losslessly and a picture
	 * is re-encoded only when it has to be. An instance where storage costs
	 * money, or whose people post straight from a 48-megapixel phone, wants
	 * the other trade, and until now had no way to ask for it.
	 */
	public const SOCIAL_IMAGE_MAX_EDGE = 'image_max_edge';

	/**
	 * The JPEG quality a re-encoded picture is stored at. Only consulted when
	 * `image_max_edge` is set, because otherwise nothing is re-encoded.
	 */
	public const SOCIAL_IMAGE_QUALITY = 'image_quality';

	/**
	 * Whether stored videos are re-encoded to H.264 in an MP4.
	 *
	 * Off by default, because re-encoding is lossy and it is somebody's file.
	 * Turning it on is how an administrator says the other trade is the one
	 * they want — and there is a concrete reason to: **Pixelfed's default
	 * `media_types` accepts `video/mp4` and nothing else**, so every
	 * `video/quicktime` posted from here, which is every video straight off an
	 * iPhone, is dropped by its inbox without a word to anybody.
	 *
	 * The work is done by a background job, never during an upload: converting
	 * a video is minutes rather than the seconds a poster frame takes.
	 */
	public const SOCIAL_VIDEO_TRANSCODE = 'video_transcode';

	/**
	 * The tallest a converted video is written. Only consulted when
	 * `video_transcode` is on, because otherwise nothing is re-encoded.
	 */
	public const SOCIAL_VIDEO_MAX_HEIGHT = 'video_max_height';

	/**
	 * Whether a stored video is also written at a ladder of smaller heights,
	 * as HLS.
	 *
	 * A different question from `video_transcode`, which is about a video
	 * being *playable at all* elsewhere. This is about it being watchable on a
	 * connection that cannot carry the original: the same video two or three
	 * more times, smaller, and a playlist that lets a player move between
	 * them. Off by default, because it is several encodes per video on
	 * somebody's server.
	 */
	public const SOCIAL_VIDEO_LADDER = 'video_ladder';

	/**
	 * Which heights, as a comma-separated list. Rungs at or above a video's
	 * own height are skipped rather than upscaled, so this is a ceiling on
	 * what may be written and not a promise about what will be.
	 */
	public const SOCIAL_VIDEO_LADDER_HEIGHTS = 'video_ladder_heights';

	/**
	 * How many megabytes of video one account may keep here, `0` for no
	 * limit.
	 *
	 * A different question from `max_video_size`, which is a ceiling on one
	 * file: that one is about a single request, this one about a year of
	 * them. Off by default, because an instance that has been running without
	 * a quota and acquires one on upgrade would start refusing uploads from
	 * exactly the accounts that use it most.
	 */
	public const SOCIAL_VIDEO_QUOTA = 'video_quota';

	/**
	 * What this instance does with a post somebody marked sensitive, for
	 * readers who have not chosen for themselves: `show_all`, `default`
	 * (covered, one press away) or `hide_all`. PeerTube's three NSFW policies
	 * under Mastodon's names for them — see `SensitiveMediaService`.
	 */
	/**
	 * Where the local-account refresh walk got to, as an `id_prim`.
	 *
	 * Bookkeeping rather than a setting. The walk used to read every local
	 * account into memory on every cron pass; it pages now, and this is what
	 * makes the next pass carry on rather than start again. `''` is "begin at
	 * the top", which is also what it is set back to when the walk reaches the
	 * end.
	 */
	public const SOCIAL_LOCAL_ACTOR_CURSOR = 'local_actor_cursor';

	/**
	 * How far back a content search looks, in days; `0` searches everything.
	 *
	 * `content ILIKE '%term%'` cannot use an index — a leading wildcard never
	 * can — so an unbounded search reads every post the instance has ever
	 * stored, joined to seven other tables, on every keystroke. At ten million
	 * rows that is a table scan the rate limit is the only defence against.
	 * A year covers what anybody is looking for; an instance small enough to
	 * search all of it can say so.
	 */
	/**
	 * Whether every recipient row carries its post's sort key yet.
	 *
	 * Set by `Version1000Date20260917000001` when its backfill finishes, and
	 * read on every home timeline to decide whether the fast page query may be
	 * used. A *flag*, because the obvious check — "is there a row with a zero
	 * nid" — has no index to answer it and is a full scan of the largest table
	 * this app has: measured at 427 ms on 800,000 rows, on every request, which
	 * would have made the thing it guards slower than what it replaced.
	 */
	public const SOCIAL_DEST_NID_FILLED = 'dest_nid_filled';

	public const SOCIAL_SEARCH_WINDOW_DAYS = 'search_window_days';

	public const SOCIAL_NSFW_POLICY = 'nsfw_policy';

	/**
	 * Whether a post with a video on it waits for a moderator.
	 *
	 * The review queue already holds a first post and a post the spam rules
	 * do not like; this is the third rule, and the one an instance that hosts
	 * video wants: a video is minutes of somebody's attention and a great deal
	 * of somebody else's disk, and an instance may reasonably want to see one
	 * before it is published. Off by default.
	 */
	public const SOCIAL_REVIEW_VIDEOS = 'review_videos';

	/**
	 * The last measurement of how much disk this app is using, as JSON, with
	 * the moment it was taken.
	 *
	 * Bookkeeping rather than a setting: the walk is a `stat` per file and
	 * belongs in the cron, so the administration page reads what the cron left
	 * here and says when it was measured. See `MediaUsageService`.
	 */
	public const SOCIAL_MEDIA_USAGE = 'media_usage';

	/**
	 * The secret a story's fetch capability is derived from, generated the
	 * first time a story is published. See `getStorySecret()`.
	 */
	public const SOCIAL_STORY_SECRET = 'story_secret';

	/**
	 * Per account, not per instance: the Pixelfed app's own switches, kept
	 * here because that app keeps them on its server so a reinstall finds the
	 * app as it was left. Written and read by `PixelfedService`, and
	 * interpreted by nothing here.
	 */
	public const APP_SETTINGS = 'app_settings';

	/** The long form of what this instance is, for `instance/extended_description`. */
	public const SOCIAL_EXTENDED_DESCRIPTION = 'extended_description';

	/**
	 * Who to write to about this instance: Mastodon's `instance.email`.
	 *
	 * Read by every client on its first request and shown on the server's
	 * about page; empty until an administrator fills it in.
	 */
	public const CONTACT_EMAIL = 'contact_email';

	public array $defaults = [
		self::CLOUD_URL => '',
		self::SOCIAL_URL => '',
		self::SOCIAL_ADDRESS => '',
		self::SOCIAL_SERVICE => 1,
		self::SOCIAL_MAX_SIZE => 10,
		self::SOCIAL_MAX_VIDEO_SIZE => 2048,
		self::SOCIAL_PUBLISH_VIDEO => '0',
		self::SOCIAL_ACCESS_TYPE => 'all_but',
		self::SOCIAL_ACCESS_LIST => '[]',
		self::SOCIAL_SELF_SIGNED => '0',
		self::SOCIAL_INBOX_THROTTLE => '300',
		self::SOCIAL_RETENTION_DAYS => '0',
		self::SOCIAL_CACHE_ACTOR_DAYS => '180',
		self::SOCIAL_SILENCED_LIST => '[]',
		self::SOCIAL_SECURE_MODE => '0',
		self::SOCIAL_PUBLISH_BLOCKS => '0',
		self::SOCIAL_REVIEW_FIRST_POST => '0',
		self::SOCIAL_AUTOSPAM => '1',
		self::SOCIAL_GIF_PACK => '1',
		self::SOCIAL_REVIEW_POSTS => '1',
		self::SOCIAL_IMAGE_MAX_EDGE => '0',
		self::SOCIAL_IMAGE_QUALITY => '85',
		self::SOCIAL_VIDEO_TRANSCODE => '0',
		self::SOCIAL_VIDEO_MAX_HEIGHT => '1080',
		self::SOCIAL_VIDEO_LADDER => '0',
		self::SOCIAL_VIDEO_LADDER_HEIGHTS => '360,720,1080',
		self::SOCIAL_VIDEO_QUOTA => '0',
		self::SOCIAL_LOCAL_ACTOR_CURSOR => '',
		self::SOCIAL_DEST_NID_FILLED => '0',
		self::SOCIAL_SEARCH_WINDOW_DAYS => '365',
		self::SOCIAL_NSFW_POLICY => 'default',
		self::SOCIAL_REVIEW_VIDEOS => '0',
		self::SOCIAL_EXTENDED_DESCRIPTION => '',
		self::CONTACT_EMAIL => '',
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
	 * A switch, read the way this app reads every other one: anything but the
	 * literal `0` is on, so an admin who writes `false`, `no` or `off` does not
	 * silently get the opposite of what they meant.
	 */
	public function getAppValueBool(string $key): bool {
		return !in_array(strtolower(trim((string)$this->getAppValue($key))), ['0', 'false', 'no', 'off', ''], true);
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
	 * The secret the story capabilities are derived from, made the first time
	 * one is needed.
	 *
	 * Generated rather than configured: nobody should have to set this, and an
	 * instance that has never published a story does not need one. Changing it
	 * invalidates every capability at once, which is the only revocation this
	 * needs — a story lives a day.
	 */
	public function getStorySecret(): string {
		$secret = $this->getAppValue(self::SOCIAL_STORY_SECRET);
		if ($secret === '') {
			$secret = bin2hex(random_bytes(32));
			$this->setAppValue(self::SOCIAL_STORY_SECRET, $secret);
		}

		return $secret;
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
