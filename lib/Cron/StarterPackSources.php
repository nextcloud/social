<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\CollectionPackService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the starter packs the `starter_pack_sources` app value names once a
 * day — the fediverse's collections and Bluesky's suggestions — so the packs
 * offered here follow what their curators publish (`CollectionPackService`).
 * A collection changes rarely, and FEP-7aa9 asks a reader to fetch again
 * from time to time because an `Add` or `Remove` may never arrive.
 *
 * One run at a time, and each stops reading after `MAX_DURATION`: what it
 * did not get to keeps what was read last time, until the next day's run.
 */
class StarterPackSources extends TimedJob {
	/** How long one run may read for, in seconds; as long as `Cron\Cache` gives a pass. */
	public const MAX_DURATION = 300;

	public function __construct(
		ITimeFactory $time,
		private ?CollectionPackService $collectionPackService = null,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setAllowParallelRuns(false);
	}

	#[\Override]
	protected function run($argument): void {
		if ($this->collectionPackService === null) {
			return;
		}

		try {
			$result = $this->collectionPackService->refresh($this->time->getTime() + self::MAX_DURATION);
		} catch (Throwable $e) {
			$this->logger?->warning('could not read the starter packs other servers publish', ['exception' => $e]);

			return;
		}

		$this->logger?->info('the starter packs other servers publish were read', $result);
	}
}
