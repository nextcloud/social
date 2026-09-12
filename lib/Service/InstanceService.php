<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\InstancesRequest;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Instance;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;

class InstanceService {
	use TArrayTools;

	/**
	 * How long a post may be, in characters. Mastodon's own limit, advertised
	 * because a client that is told no limit assumes 500 and greys out the
	 * button on the 501st character. Nothing truncates to it: `PostService`
	 * refuses a post over it, so what a client is told is what it gets.
	 */
	public const MAX_CHARACTERS = 5000;

	/**
	 * Every mime type this app could conceivably accept an upload of.
	 *
	 * This is *not* the allowlist — `CacheDocumentService::filterMimeTypes()`
	 * is, and each candidate below is put to it, so a type that service refuses
	 * never reaches a client. It only bounds the question: a type the service
	 * starts accepting that is missing from here would be under-reported (a
	 * client would not offer it), never over-reported (a client offering an
	 * upload the server then rejects).
	 */
	private const MIME_CANDIDATES = [
		'image/jpeg', 'image/gif', 'image/png', 'image/webp', 'image/avif', 'image/heic',
		'image/bmp', 'image/tiff', 'image/svg+xml',
		'video/mp4', 'video/webm', 'video/quicktime', 'video/ogg', 'video/x-matroska',
		'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/opus', 'audio/wav', 'audio/x-wav',
		'audio/flac', 'audio/aac', 'audio/webm', 'audio/3gpp',
	];

	/**
	 * Where the counted `status_count` and `domain_count` are remembered, as
	 * JSON with a `computed_at` timestamp, and for how long. Five minutes is
	 * fresh enough for a number nobody reads to the unit, and it keeps the two
	 * table walks behind it off the request path.
	 */
	public const STATS_CACHE_KEY = 'instance_stats';
	public const STATS_CACHE_SECONDS = 300;

	/** Weeks of history `/api/v1/instance/activity` answers; Mastodon's is 12. */
	private const ACTIVITY_WEEKS = 12;
	private const WEEK = 7 * 24 * 3600;

	/** @var string[]|null memoised: filterMimeTypes() does not change mid-request */
	private ?array $supportedMimeTypes = null;

	/** @var array{status_count: int, domain_count: int}|null memoised per request */
	private ?array $countedStats = null;

	public function __construct(
		private InstancesRequest $instancesRequest,
		private ConfigService $configService,
		private MiscService $miscService,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
		private CacheDocumentService $cacheDocumentService,
		private InstanceStatsRequest $instanceStatsRequest,
	) {
	}

	public function createLocal(): Instance {
		$instance = new Instance();
		$instance->setLocal(true);
		$this->fillLocal($instance);
		$this->instancesRequest->save($instance);

		return $instance;
	}

	/**
	 * @throws InstanceDoesNotExistException
	 */
	public function getLocal(int $format = ACore::FORMAT_LOCAL): Instance {
		try {
			$instance = $this->instancesRequest->getLocal($format);
		} catch (InstanceDoesNotExistException $e) {
			return $this->createLocal();
		}

		// The row is written once, at install, and never updated: an instance
		// that has since been renamed, moved to another address or upgraded
		// would keep answering with whatever was true on the day it was set up.
		// Everything a client is told is derived here, from the configuration
		// as it stands now, so the stored row is only a marker that the
		// instance exists.
		$this->fillLocal($instance);

		return $instance;
	}

	/**
	 * Everything /api/v1/instance and /api/v2/instance answer with.
	 *
	 * This is the first request every Mastodon client makes, and it decides
	 * whether the client will talk to this server at all: an empty `uri`, or a
	 * missing `configuration`, reads as "cannot connect to server".
	 */
	private function fillLocal(Instance $instance): void {
		$cloudHost = $this->configService->getCloudHost();
		$socialAddress = $this->configService->getSocialAddress();
		$uri = ($socialAddress !== '') ? $socialAddress : $cloudHost;

		$instance->setUri($uri)
			->setVersion($this->appConfig->getValueString(Application::APP_ID, 'installed_version', '0.0'))
			->setTitle($this->appConfig->getValueString('theming', 'name', 'Nextcloud Social'))
			->setShortDescription(
				$this->appConfig->getValueString('theming', 'slogan', 'a safe home for your data')
			)
			->setDescription(
				$this->appConfig->getValueString('theming', 'slogan', 'a safe home for your data')
			)
			->setEmail($this->appConfig->getValueString(Application::APP_ID, 'contact_email', ''))
			->setImage($this->thumbnail())
			->setLanguages([$this->defaultLanguage()])
			// Accounts are Nextcloud users; the client API cannot create one,
			// so a client must not offer a sign-up form for this server.
			->setRegistrations(false)
			->setApprovalRequired(false)
			->setInvitesEnabled(false)
			->setUrls($this->urls())
			->setStats($this->stats())
			->setUsage($this->usage())
			->setConfiguration($this->configuration())
			->setRules($this->rules());
	}

	/**
	 * Mastodon's `urls`, whose only member is the streaming endpoint. This app
	 * has no streaming API, and a client handed a URL that never upgrades to a
	 * websocket falls back to polling only after a timeout — so the key is left
	 * out and the client polls from the start.
	 */
	private function urls(): array {
		return [];
	}

	/**
	 * `stats` as an object with all three keys, all of them live.
	 *
	 * `user_count` is the number of Nextcloud users, which is the number of
	 * people who can have an account here: an actor is created for a user the
	 * first time they open the app. `status_count` and `domain_count` are
	 * counted from the database and remembered for a few minutes, see
	 * `countedStats()`. Nothing is read back from the stored row: it holds
	 * whatever was true on the day the instance was set up.
	 */
	/**
	 * The instances this one has heard of, for `/api/v1/instance/peers`.
	 *
	 * @return string[]
	 */
	public function getPeers(): array {
		return $this->instanceStatsRequest->getRemoteDomains();
	}

	/**
	 * Twelve weeks of activity, newest first, in Mastodon's shape: every value
	 * a string, every week keyed by the unix time its Monday began.
	 *
	 * `logins` and `registrations` are `0` and cannot be otherwise: an account
	 * here is a Nextcloud user, so both belong to the server rather than to
	 * this app. `statuses` is real.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function getWeeklyActivity(): array {
		$weeks = [];
		$startOfWeek = strtotime('monday this week 00:00:00');
		if ($startOfWeek === false) {
			return [];
		}

		for ($week = 0; $week < self::ACTIVITY_WEEKS; $week++) {
			$from = $startOfWeek - ($week * self::WEEK);
			$weeks[] = [
				'week' => (string)$from,
				'statuses' => (string)$this->instanceStatsRequest->countLocalStatusesBetween(
					$from, $from + self::WEEK
				),
				'logins' => '0',
				'registrations' => '0',
			];
		}

		return $weeks;
	}

	private function stats(): array {
		$counted = $this->countedStats();

		return [
			'user_count' => $this->countUsers(),
			'status_count' => $counted['status_count'],
			'domain_count' => $counted['domain_count'],
		];
	}

	/** NodeInfo's shape for the same numbers. */
	private function usage(): array {
		$stats = $this->stats();

		return [
			'users' => ['total' => $stats['user_count']],
			'localPosts' => $stats['status_count'],
		];
	}

	/**
	 * The two counted numbers, from app config when a recent count is there,
	 * from the database otherwise — and then written back for the next
	 * STATS_CACHE_SECONDS. Also memoised for the request, since the instance
	 * entity is built more than once per response.
	 *
	 * @return array{status_count: int, domain_count: int}
	 */
	private function countedStats(): array {
		if ($this->countedStats !== null) {
			return $this->countedStats;
		}

		$remembered = json_decode(
			$this->appConfig->getValueString(Application::APP_ID, self::STATS_CACHE_KEY, ''), true
		);
		if (is_array($remembered)
			&& (int)($remembered['computed_at'] ?? 0) > time() - self::STATS_CACHE_SECONDS) {
			return $this->countedStats = [
				'status_count' => (int)($remembered['status_count'] ?? 0),
				'domain_count' => (int)($remembered['domain_count'] ?? 0),
			];
		}

		$counted = [
			'status_count' => $this->instanceStatsRequest->countLocalStatuses(),
			'domain_count' => $this->instanceStatsRequest->countRemoteDomains(),
		];
		$this->appConfig->setValueString(
			Application::APP_ID, self::STATS_CACHE_KEY,
			(string)json_encode($counted + ['computed_at' => time()])
		);

		return $this->countedStats = $counted;
	}

	private function countUsers(): int {
		$total = 0;
		foreach ($this->userManager->countUsers() as $count) {
			$total += (int)$count;
		}

		return $total;
	}

	/**
	 * The limits a client has to respect before it lets someone write a post
	 * the server would refuse.
	 */
	private function configuration(): array {
		return [
			'statuses' => [
				'max_characters' => self::MAX_CHARACTERS,
				'max_media_attachments' => Stream::MAX_ATTACHMENTS,
				'characters_reserved_per_url' => 23,
			],
			'media_attachments' => [
				'supported_mime_types' => $this->supportedMimeTypes(),
				'image_size_limit' => $this->maxUploadSize(),
				'video_size_limit' => $this->maxUploadSize(),
				'image_matrix_limit' => CacheDocumentService::MAX_PIXELS,
				'video_frame_rate_limit' => 0,
				'video_matrix_limit' => 0,
			],
			'polls' => [
				'max_options' => PostService::POLL_MAX_OPTIONS,
				'max_characters_per_option' => 100,
				'min_expiration' => PostService::POLL_MIN_EXPIRATION,
				'max_expiration' => PostService::POLL_MAX_EXPIRATION,
			],
			'accounts' => [
				'max_featured_tags' => FeaturedTagService::MAX_FEATURED_TAGS,
			],
		];
	}

	/**
	 * The mime types an upload may actually have, asked of the one place that
	 * decides: `CacheDocumentService::filterMimeTypes()`. Duplicating its list
	 * here would let the two drift, and a client would then offer an upload the
	 * server refuses.
	 *
	 * @return string[]
	 */
	public function supportedMimeTypes(): array {
		if ($this->supportedMimeTypes !== null) {
			return $this->supportedMimeTypes;
		}

		$supported = [];
		foreach (self::MIME_CANDIDATES as $mime) {
			try {
				$this->cacheDocumentService->filterMimeTypes($mime);
				$supported[] = $mime;
			} catch (CacheContentMimeTypeException $e) {
			}
		}

		return $this->supportedMimeTypes = $supported;
	}

	/** The upload ceiling, in bytes, from the app's own `max_size` (in MB). */
	public function maxUploadSize(): int {
		$megabytes = $this->configService->getAppValueInt(ConfigService::SOCIAL_MAX_SIZE);

		return (($megabytes > 0) ? $megabytes : 10) * 1048576;
	}

	/**
	 * The instance rules, as Mastodon's Rule entities. Stored as one rule per
	 * line in the `rules` app value, so an admin can set them with
	 * `occ config:app:set social rules --value "..."`.
	 *
	 * @return array<array{id: string, text: string}>
	 */
	private function rules(): array {
		$stored = trim($this->appConfig->getValueString(Application::APP_ID, 'rules', ''));
		if ($stored === '') {
			return [];
		}

		$rules = [];
		foreach (preg_split('/\r\n|\r|\n/', $stored) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}

			$rules[] = ['id' => (string)(count($rules) + 1), 'text' => $line];
		}

		return $rules;
	}

	private function defaultLanguage(): string {
		$language = $this->config->getSystemValue('default_language', 'en');

		return is_string($language) && $language !== '' ? $language : 'en';
	}

	private function thumbnail(): string {
		try {
			return $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(Application::APP_ID, 'social.svg')
			);
		} catch (Exception $e) {
			return '';
		}
	}
}
