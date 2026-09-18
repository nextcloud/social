<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Evicts the cached remote actors nothing here refers to any more.
 *
 * A cached actor row was written the first time this instance met the
 * account — a like on a local post, a boost seen in a timeline, a reply in a
 * thread — and until now nothing ever removed one except the account's own
 * instance sending a `Delete`, or a moderator purging its domain. A year of
 * federating leaves tens of thousands of such rows, each with an avatar in
 * appdata, for accounts nobody here has anything to do with.
 *
 * An actor is swept when nobody here follows it, it follows nobody here, no
 * post of its is stored, no follow request or block/mute/endorsement names it
 * either way, and it has been neither seen nor tried for `cache_actor_days`
 * (default 180; 0 disables). Anything swept is fetched again the moment it is
 * needed, so what is lost is a request, never a relationship or a post. Local
 * actors are never touched.
 *
 * A like or a boost of a local post is deliberately not one of the conditions,
 * which is also where Mastodon's `tootctl accounts prune` draws the line: the
 * row in `social_action` stays and names the actor, and the profile behind it
 * is fetched again when somebody opens the list of who liked the post. Adding
 * it would mean a fourth `NOT EXISTS` against the second-largest table in the
 * app, on every pass, to keep rows for accounts that touched one post once.
 *
 * Paged like `StreamPruneService`: the page is read, deleted, and read again,
 * bounded per cron pass so eviction never dominates a slot.
 */
class CacheActorSweepService {
	public const PAGE = 100;

	public function __construct(
		private ConfigService $configService,
		private CacheActorsRequest $cacheActorsRequest,
		private MediaPurgeService $mediaPurgeService,
		private LoggerInterface $logger,
		private ?ITimeFactory $timeFactory = null,
	) {
	}

	public function getSweepDays(): int {
		return max(0, $this->configService->getAppValueInt(ConfigService::SOCIAL_CACHE_ACTOR_DAYS));
	}

	/**
	 * @param int|null $days the age past which an unreferenced actor goes; the
	 *                       `cache_actor_days` setting when null
	 * @param bool $dryRun count what would go and remove nothing
	 * @param int $max stop after this many actors; 0 for all of them
	 *
	 * @return array{actors: int, documents: int} what was (or would be) removed
	 */
	public function sweep(?int $days = null, bool $dryRun = false, int $max = 0): array {
		$days ??= $this->getSweepDays();
		if ($days <= 0) {
			return ['actors' => 0, 'documents' => 0];
		}

		$cutoff = ($this->timeFactory?->getTime() ?? time()) - $days * 86400;

		if ($dryRun) {
			// one read, because the query answers "the next ones to go" and has
			// no cursor: with nothing deleted a second read returns the same
			// rows. So a dry run counts the ceiling it was given, or a page
			$ids = $this->cacheActorsRequest->getSweepableIds($cutoff, $max > 0 ? $max : self::PAGE);

			return ['actors' => count($ids), 'documents' => 0];
		}

		$actors = 0;
		$documents = 0;
		while (true) {
			$limit = self::PAGE;
			if ($max > 0) {
				$limit = min($limit, $max - $actors);
				if ($limit <= 0) {
					break;
				}
			}

			$ids = $this->cacheActorsRequest->getSweepableIds($cutoff, $limit);
			if ($ids === []) {
				break;
			}

			foreach ($ids as $id) {
				$documents += $this->evict($id);
				$actors++;
			}
		}

		if ($actors > 0) {
			$this->logger->info('swept cached remote actors nobody here refers to', [
				'days' => $days, 'actors' => $actors, 'documents' => $documents,
			]);
		}

		return ['actors' => $actors, 'documents' => $documents];
	}

	/**
	 * One actor and what hangs off it: the files first, because the rows are
	 * the only thing that remembers the files' names.
	 *
	 * @return int the document rows removed with it
	 */
	private function evict(string $id): int {
		$documents = $this->mediaPurgeService->purgeByParent($id);
		$this->cacheActorsRequest->deleteCacheById($id);

		return $documents;
	}
}
