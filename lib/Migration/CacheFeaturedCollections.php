<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Refresh the cached copy of every local actor, once, when something the cache
 * carries has changed.
 *
 * Two things have needed this so far: pinned posts added a `featured` URL that
 * actors cached earlier do not have, and the display name only started being
 * published at all once the scope check was relaxed (it required
 * SCOPE_PUBLISHED, while Nextcloud's default is SCOPE_FEDERATED, so no local
 * actor had a `name` and every client fell back to the user id).
 *
 * The marker is what makes this affordable. Rebuilding one actor's cache is
 * several queries plus avatar handling, so doing it for every local actor on
 * *every* app upgrade — which is what this step used to do, on the grounds
 * that re-runs are "harmless" — costs an instance with many users a great deal
 * of time inside `occ upgrade`, with the instance in maintenance mode, forever.
 * Bump VERSION when a change needs the cache rebuilt again.
 */
class CacheFeaturedCollections implements IRepairStep {
	/** Bump this when a change means local actor caches must be rebuilt again. */
	private const VERSION = 2;

	/** Above this many local actors, hand the job to the admin rather than to `occ upgrade`. */
	private const INLINE_LIMIT = 500;

	private const MARKER = 'migration_local_actor_cache';

	public function __construct(
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
		private ConfigService $configService,
	) {
	}

	public function getName(): string {
		return 'Refresh the cached copy of local actors';
	}

	public function run(IOutput $output): void {
		if ($this->configService->getAppValueInt(self::MARKER) >= self::VERSION) {
			return;
		}

		$actors = $this->actorsRequest->getAll();
		if (count($actors) > self::INLINE_LIMIT) {
			// Doing this inline would hold the upgrade open for a long time.
			// `occ social:cache:refresh` does the same work and can be run
			// whenever it suits the operator.
			$output->warning(
				sprintf(
					'%d local actors need their cache refreshed; skipping it here to keep the '
					. 'upgrade short. Run "occ social:cache:refresh" to publish their display '
					. 'names and featured collections.',
					count($actors)
				)
			);
			$this->configService->setAppValue(self::MARKER, (string)self::VERSION);

			return;
		}

		$refreshed = 0;
		$output->startProgress(count($actors));
		foreach ($actors as $actor) {
			try {
				$this->accountService->cacheLocalActorByUsername($actor->getPreferredUsername());
				$refreshed++;
			} catch (\Exception $e) {
				$output->warning(
					'could not refresh the actor cache of ' . $actor->getPreferredUsername()
					. ': ' . $e->getMessage()
				);
			}
			$output->advance();
		}
		$output->finishProgress();

		if ($refreshed > 0) {
			$output->info(sprintf('refreshed the cache of %d local actor(s)', $refreshed));
		}

		$this->configService->setAppValue(self::MARKER, (string)self::VERSION);
	}
}
