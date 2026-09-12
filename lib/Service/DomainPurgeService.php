<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use Psr\Log\LoggerInterface;

/**
 * Removing what a blocked instance already sent us.
 *
 * Blocking a domain only ever stopped the *next* request:
 * `FediverseService::authorized()` refuses an incoming delivery and an
 * outgoing fetch, and `DomainBlocksRequestBuilder::filterDomainBlocked()`
 * hides the posts from a timeline. Everything the instance sent before the
 * block stayed exactly where it was — its accounts in the actor cache and so
 * in search and in the directory, its posts in every thread and in the
 * database, its follows in both directions (which kept it in the delivery
 * fan-out of every local post), and the notifications it caused. An admin who
 * blocks a domain after an incident means "get this off my server", and the
 * block on its own did not do that. Mastodon's domain block purges, and this
 * is that.
 *
 * **Bounded.** One call deletes at most `$limit` accounts' worth of data and
 * says how many it did, so the caller decides how long to keep going. Nothing
 * here opens a transaction of its own: each account is detached by the same
 * per-account deletes a suspension uses, each of which is already batched. An
 * instance with a hundred thousand posts here is a hundred thousand rows
 * deleted a few thousand at a time, never one statement holding a lock over
 * the whole table.
 *
 * **Idempotent.** The work is found by asking what of the domain is still
 * stored, never by an offset or a cursor: an account is named by
 * `purgeStep()` only while it still has a cached actor, a post or a follow
 * here. So a purge that dies half way is resumed by running it again, running
 * it on an already-purged domain does nothing, and two of them racing each
 * other only repeat deletes that no longer match anything.
 *
 * **Exactly the domain named.** A subdomain of a purged instance is left
 * alone, even though `FediverseService::isListed()` widens a block to cover
 * one: refusing traffic from an instance too many is undone by editing the
 * list, and deleting an instance too many is not. An admin who means to
 * include a subdomain purges it too.
 *
 * **Unblocking resurrects nothing.** What this deletes is gone; lifting the
 * block lets the instance reach us again, and what it sends from then on is
 * new. That is the same promise `ModerationService` makes about lifting a
 * suspension, and `occ social:domain:purge` says so before it starts.
 */
class DomainPurgeService {
	/**
	 * Accounts detached per `purgeStep()`. Each one costs several batched
	 * deletes, so this is deliberately small: a cron slot or an occ run gets
	 * through it quickly and can stop between steps rather than in the middle
	 * of one.
	 */
	public const BATCH = 50;

	/**
	 * How many relationships of one account a severed-relationships count
	 * looks at. A local account that follows more than this on one instance
	 * loses more than the notification says, and a number is what it is for.
	 */
	private const SEVERED_MAX = 500;

	public function __construct(
		private CacheActorsRequest $cacheActorsRequest,
		private StreamRequest $streamRequest,
		private FollowsRequest $followsRequest,
		private ModerationService $moderationService,
		private ConfigService $configService,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Purges a domain completely.
	 *
	 * @param int $maxSteps how many batches to run before giving up on making
	 *                      progress, or 0 for no ceiling. A ceiling is what a
	 *                      cron run wants; an `occ` run wants to finish.
	 *
	 * @return int how many accounts were detached
	 *
	 * @throws InvalidResourceException the domain is not one, or is this
	 *                                  instance's own
	 */
	public function purge(string $domain, int $maxSteps = 0): int {
		$domain = $this->assertRemoteDomain($domain);

		$purged = 0;
		$previous = null;
		for ($step = 0; ($maxSteps === 0) || ($step < $maxSteps); $step++) {
			$batch = $this->remaining($domain, self::BATCH);
			if ($batch === []) {
				break;
			}

			if ($batch === $previous) {
				// the same accounts came back, so the last pass deleted
				// nothing and the next one would select the very same rows.
				// A detach swallows the failure of each table it touches (a
				// purge must not stop at the first locked one), which without
				// this makes a database that refuses the delete an endless
				// loop rather than a logged failure.
				$this->logger->warning('a domain purge stopped making progress', [
					'domain' => $domain, 'accounts' => $batch,
				]);
				break;
			}

			$this->detach($batch);
			$previous = $batch;
			$purged += count($batch);
		}

		if ($purged > 0) {
			$this->logger->info('purged accounts of a blocked instance', [
				'domain' => $domain, 'accounts' => $purged,
			]);
		}

		return $purged;
	}

	/**
	 * One batch.
	 *
	 * @return int accounts detached; 0 means there is nothing of the domain
	 *             left here, which is how every caller knows to stop
	 *
	 * @throws InvalidResourceException
	 */
	public function purgeStep(string $domain): int {
		$domain = $this->assertRemoteDomain($domain);

		$actorIds = $this->remaining($domain, self::BATCH);
		$this->detach($actorIds);

		return count($actorIds);
	}

	/**
	 * @param string[] $actorIds
	 */
	private function detach(array $actorIds): void {
		foreach ($actorIds as $actorId) {
			// counted before the purge, because the purge is what removes them:
			// afterwards there is nothing left to count and nobody to tell
			$severed = $this->localRelationshipsWith($actorId);
			$this->moderationService->purgeActor($actorId);
			$this->tellThemTheirFollowsWereCut($severed, $actorId);
		}
	}

	/**
	 * The local accounts that follow, or are followed by, an account about to
	 * be purged, and how many relationships each of them loses.
	 *
	 * @return array<string, int> local actor id => how many
	 */
	private function localRelationshipsWith(string $actorId): array {
		$counts = [];

		foreach ([
			$this->followsRequest->getFollowersByActorId($actorId, self::SEVERED_MAX),
			$this->followsRequest->getFollowingByActorId($actorId, self::SEVERED_MAX),
		] as $follows) {
			foreach ($follows as $follow) {
				foreach ([$follow->getActorId(), $follow->getObjectId()] as $side) {
					if ($side !== $actorId && $this->isLocal($side)) {
						$counts[$side] = ($counts[$side] ?? 0) + 1;
					}
				}
			}
		}

		return $counts;
	}

	/**
	 * Tells the local accounts a block has cut off.
	 *
	 * Until this they were told nothing: they simply stopped seeing somebody
	 * and had no way of learning why. Mastodon calls it
	 * `severed_relationships`, and it is the one notification whose whole
	 * point is that the thing it is about has already happened.
	 *
	 * @param array<string, int> $severed
	 */
	private function tellThemTheirFollowsWereCut(array $severed, string $actorId): void {
		$domain = strtolower((string)parse_url($actorId, PHP_URL_HOST));
		if ($domain === '') {
			return;
		}

		foreach ($severed as $local => $lost) {
			try {
				$this->notificationService->onRelationshipsSevered($local, $domain, $lost);
			} catch (\Exception $e) {
				// the purge is the point and has already happened; a
				// notification that could not be written must not stop it
				$this->logger->warning('could not tell an account its follows were cut', [
					'actor' => $local, 'exception' => $e,
				]);
			}
		}
	}

	/** Whether an actor id is one this instance serves. */
	private function isLocal(string $actorId): bool {
		return str_starts_with($actorId, $this->configService->getSocialUrl());
	}

	/**
	 * Whether anything of the domain is still stored here.
	 *
	 * @throws InvalidResourceException
	 */
	public function hasRemains(string $domain): bool {
		return $this->remaining($this->assertRemoteDomain($domain), 1) !== [];
	}

	/**
	 * The accounts of the domain that still have something here, from the
	 * three places one can be left behind.
	 *
	 * The actor cache alone is not enough: a post can outlive the actor it was
	 * cached from, and a follow row names an account this instance may never
	 * have cached at all. Asking all three means the answer only empties when
	 * every one of them has, which is what makes the purge terminate for the
	 * right reason.
	 *
	 * @return string[] actor ids
	 *
	 * @throws InvalidResourceException
	 */
	private function remaining(string $domain, int $limit): array {
		$found = [];
		foreach ([
			fn (): array => $this->cacheActorsRequest->getIdsFromDomain($domain, $limit),
			fn (): array => $this->streamRequest->getAuthorsFromDomain($domain, $limit),
			fn (): array => $this->followsRequest->getActorIdsFromDomain($domain, $limit),
		] as $source) {
			foreach ($source() as $actorId) {
				$found[$actorId] = true;
				if (count($found) >= $limit) {
					return array_keys($found);
				}
			}
		}

		return array_keys($found);
	}

	/**
	 * @throws InvalidResourceException purging this instance's own domain
	 *                                  would delete every local account's
	 *                                  posts, follows and notifications, and
	 *                                  no block could ever have asked for that
	 */
	private function assertRemoteDomain(string $domain): string {
		$domain = DomainBlockService::normalise($domain);

		$local = array_filter([
			strtolower($this->configService->getSocialAddress()),
			strtolower($this->configService->getCloudHost()),
		]);
		if (in_array($domain, $local, true)) {
			throw new InvalidResourceException('cannot purge this instance');
		}

		return $domain;
	}
}
