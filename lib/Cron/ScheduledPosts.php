<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\ScheduledStatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes the posts whose time has come.
 *
 * A job of its own rather than a step of `Cron\Cache`, because it is the only
 * thing in this app whose lateness a user notices: everything that job does is
 * a cache that is merely stale until the next pass, while this one is somebody
 * waiting for a post to appear at the time they picked.
 */
class ScheduledPosts extends TimedJob {
	/**
	 * The other two jobs run every twelve minutes, which would make every
	 * scheduled post up to twelve minutes late. Five matches
	 * `ScheduledStatusService::MIN_LEAD_TIME` — the shortest notice a post may
	 * be accepted on — so the worst case is that a post goes out one period
	 * after the minute it was asked for rather than in some interval longer
	 * than the notice the API demanded for it.
	 *
	 * Nothing calls `setTimeSensitivity()` here: time-sensitive is what a job
	 * already is, and this one has to stay that way. An instance that defers
	 * non-urgent jobs to off-peak hours would otherwise publish an evening post
	 * the next morning, which is precisely the failure the feature exists to
	 * prevent.
	 */
	public function __construct(
		ITimeFactory $time,
		private ScheduledStatusService $scheduledStatusService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
	}

	#[\Override]
	protected function run($argument) {
		try {
			$published = $this->scheduledStatusService->publishDue();
			if ($published > 0) {
				$this->logger->info('[Cron\\ScheduledPosts] published ' . $published . ' scheduled posts');
			}
		} catch (Throwable $e) {
			// publishDue() already survives one post failing; reaching here
			// means the database or the container did, and the next run is the
			// retry. Logged rather than thrown, because an exception out of a
			// job is what makes Nextcloud stop scheduling it.
			$this->logger->warning(
				'[Cron\\ScheduledPosts] the run failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}
}
