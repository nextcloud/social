<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\DomainPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Removes what a newly blocked instance already sent us.
 *
 * Queued with the domain as its argument by `FediverseService::addAddress()`,
 * so — unlike `Cache` and `Queue` — it is not registered in `info.xml`: a job
 * listed there is added once at install time with no argument, and this one is
 * meaningless without a domain.
 *
 * **Why it re-queues itself.** A cron run is a slot shared with every other
 * job on the server, and an instance that federated with us for years can have
 * more here than fits in one. So it does a bounded number of batches and, if
 * anything of the domain is left, puts itself back on the list for the next
 * run. Each pass finds its own work by asking what is still stored (see
 * `DomainPurgeService`), so a pass that dies takes nothing with it and the
 * next one resumes where it stopped.
 *
 * **Why it does not check the block.** An admin who blocks and then quickly
 * unblocks has already had a purge start; finishing it is the honest
 * behaviour, because what the first pass deleted is not coming back and a
 * half-purged instance is worse than a purged one. `occ social:domain:purge`
 * exists for the same reason in reverse: purging is a decision of its own,
 * separable from the block.
 */
class DomainPurge extends QueuedJob {
	/**
	 * Batches per run. `DomainPurgeService::BATCH` accounts each, so a few
	 * hundred accounts a pass — enough that a small instance is finished in
	 * one, bounded enough that a large one never owns a cron slot.
	 */
	private const STEPS_PER_RUN = 10;

	public function __construct(
		ITimeFactory $time,
		private DomainPurgeService $domainPurgeService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$domain = is_array($argument) ? (string)($argument['domain'] ?? '') : '';
		if ($domain === '') {
			return;
		}

		try {
			$purged = $this->domainPurgeService->purge($domain, self::STEPS_PER_RUN);
			if ($this->domainPurgeService->hasRemains($domain)) {
				$this->jobList->add(self::class, ['domain' => $domain]);
			}

			$this->logger->info('[Cron\\DomainPurge] purged a blocked instance', [
				'domain' => $domain, 'accounts' => $purged,
			]);
		} catch (\Throwable $e) {
			// a purge that throws must not be retried forever: it is logged,
			// the rows it did delete stay deleted, and an admin can finish it
			// with occ social:domain:purge
			$this->logger->warning('[Cron\\DomainPurge] could not purge ' . $domain, [
				'domain' => $domain, 'exception' => $e,
			]);
		}
	}
}
