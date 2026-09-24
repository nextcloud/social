<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\BlocklistSubscriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-reads the block lists this instance follows.
 *
 * Daily, because a published list is a moderation decision and not a feed:
 * asking more often would be a request an hour at every server whose list an
 * admin follows, for a file that changes a handful of times a week.
 *
 * Does nothing at all until an admin has turned a source on, which is the
 * common case — so the cost of this job on an instance that does not use the
 * feature is one read of one app setting a day.
 */
class BlocklistSync extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ?BlocklistSubscriptionService $subscriptionService = null,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($time);

		$this->setInterval(24 * 3600);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument) {
		if ($this->subscriptionService === null || !$this->subscriptionService->anyEnabled()) {
			return;
		}

		try {
			$results = $this->subscriptionService->fetchEnabled();
		} catch (Throwable $e) {
			$this->logger?->warning('could not refresh the subscribed block lists', ['exception' => $e]);

			return;
		}

		foreach ($results as $id => $result) {
			// at info, because this changes what the instance federates with:
			// an admin looking at why a server went away should find the run
			// that did it
			$this->logger?->info('a subscribed block list was applied', [
				'source' => $id,
			] + $result);
		}
	}
}
