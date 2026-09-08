<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Service\AccountService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Local actors cached before pinned posts landed carry no `featured` URL, so
 * remote servers would not know where to look for their pinned posts until
 * the next cache refresh. Re-caching the local actors publishes it right
 * away; the cache is rebuilt from the actor row, so re-runs are harmless.
 */
class CacheFeaturedCollections implements IRepairStep {
	public function __construct(
		private ActorsRequest $actorsRequest,
		private AccountService $accountService,
	) {
	}

	public function getName(): string {
		return 'Publish the featured collection of local actors';
	}

	public function run(IOutput $output): void {
		$refreshed = 0;
		foreach ($this->actorsRequest->getAll() as $actor) {
			try {
				$this->accountService->cacheLocalActorByUsername($actor->getPreferredUsername());
				$refreshed++;
			} catch (\Exception $e) {
				$output->warning(
					'could not refresh the actor cache of ' . $actor->getPreferredUsername()
					. ': ' . $e->getMessage()
				);
			}
		}

		if ($refreshed > 0) {
			$output->info(sprintf('featured collection published for %d local actor(s)', $refreshed));
		}
	}
}
