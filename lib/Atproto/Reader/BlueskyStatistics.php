<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\Statistics\StatisticsSource;

/**
 * Bluesky's part of the statistics page.
 *
 * A Bluesky account's id here is its profile address on `bsky.app`, which is
 * a website and not where the account lives: that is its PDS, which the DID
 * document names and `ActorMapper` keeps. Bluesky runs most accounts on PDS
 * hosts under `host.bsky.network`, dozens of them, which nobody would
 * recognise and which are one service; they are shown as `bsky.social`, the
 * name the accounts on them carry.
 *
 * A post that went out on Bluesky is a record in its author's repository,
 * read straight from there: it is never delivered to an instance, so it has
 * no instance path.
 */
class BlueskyStatistics implements StatisticsSource {
	public const NETWORK = 'Bluesky';

	/** The name the accounts on Bluesky's own PDS hosts go by. */
	public const BLUESKY_HOST = 'bsky.social';

	public function __construct(
		private AtprotoConfig $config,
		private CacheActorsRequest $cacheActorsRequest,
		private IdentityService $identities,
		private AtprotoRepoRequest $repositories,
	) {
	}

	#[\Override]
	public function homeServers(array $actorIds): array {
		$ids = array_values(array_filter($actorIds, static fn (string $id): bool => BlueskyIds::isActorId($id)));
		if ($ids === []) {
			return [];
		}

		$actors = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$actors += $this->cacheActorsRequest->getFromIds($chunk);
		}

		$hosts = [];
		foreach ($ids as $id) {
			$pds = (string)(($actors[$id] ?? null)?->getDetails(ActorMapper::DETAIL)['pds'] ?? '');
			$hosts[$id] = self::hostOf($pds);
		}

		return $hosts;
	}

	#[\Override]
	public function reached(Person $actor, array $postIds): ?array {
		if (!$this->config->isEnabled()) {
			return null;
		}
		$identity = $this->identities->forActor($actor, false);
		if ($identity === null) {
			return null;
		}

		return [
			'network' => self::NETWORK,
			'posts' => $this->repositories->countRecordsOf($identity->did, RecordMapper::POST, $postIds),
		];
	}

	/**
	 * The server an account on Bluesky lives on, from its PDS endpoint;
	 * Bluesky's own hosts as one, and an unknown PDS as Bluesky's, which is
	 * where an account whose DID document was never read is all but always.
	 *
	 * The account writes its own DID document, so an endpoint whose host is
	 * not a hostname is taken as unknown too: `parse_url()` hands back
	 * whatever stands between `//` and the path, `=HYPERLINK(…)` included.
	 */
	public static function hostOf(string $pds): string {
		$host = strtolower((string)parse_url($pds, PHP_URL_HOST));
		if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
			$host = '';
		}
		if ($host === '' || $host === 'bsky.social' || $host === 'bsky.network' || str_ends_with($host, '.bsky.network')) {
			return self::BLUESKY_HOST;
		}

		return $host;
	}
}
