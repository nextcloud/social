<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\SubscriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-reads the feeds people here follow.
 *
 * For a bounded time per pass (`SubscriptionService::PASS_SECONDS`), several
 * at once, never-read first and then stalest first, so however many there are
 * the pass ends well inside the interval and the next one carries on. Each
 * read is conditional, so a feed that has not changed costs a 304.
 */
class Subscriptions extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ?SubscriptionService $subscriptionService = null,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($time);

		$this->setInterval(15 * 60);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument) {
		if ($this->subscriptionService === null) {
			return;
		}

		try {
			$this->subscriptionService->refreshDue();
		} catch (Throwable $e) {
			$this->logger?->warning('refreshing the followed feeds failed', ['exception' => $e]);
		}
	}
}
