<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\StoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes the stories whose day is up.
 *
 * The second of the two guards on a story's expiry, and the one that actually
 * removes the picture. The first is that every read filters on `expires_at`, so
 * an instance whose cron has stopped shows nothing it should not -- but the rows
 * and the files behind them would pile up for ever, and "it disappears after a
 * day" would be true of what people can see and false of what is stored. For a
 * feature whose whole promise is that the thing goes away, those have to be the
 * same statement.
 *
 * Bounded per run, like every other sweep here: an instance that has not run
 * cron in a week has more due than one slot should take, and what is left is the
 * next run's.
 */
class ExpiredStories extends TimedJob {
	/**
	 * Hourly. A story lives a day, so the granularity that matters is hours,
	 * not minutes -- and unlike a scheduled post, nobody is watching the clock
	 * for this one: the read filter has already stopped showing it.
	 */
	private const INTERVAL = 3600;

	public function __construct(
		ITimeFactory $time,
		private StoryService $storyService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		try {
			$removed = $this->storyService->purgeExpired();
			if ($removed > 0) {
				$this->logger->debug('[Cron\\ExpiredStories] removed expired stories', [
					'count' => $removed,
				]);
			}
		} catch (Throwable $e) {
			// a sweep that fails is retried on the next pass; the read filter
			// means nothing expired is visible in the meantime
			$this->logger->warning('[Cron\\ExpiredStories] could not remove expired stories', [
				'exception' => $e,
			]);
		}
	}
}
