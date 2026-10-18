<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\VideoPosterBackfill;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Poster frames for the stored videos that were kept without one.
 *
 * **A few videos per run** (`VideoPosterBackfill::BATCH`), every fifteen
 * minutes: each is a copy out of storage and an ffmpeg, and a still for an
 * old video is nice to have rather than urgent. Once every video has been
 * looked at, a run is one query that finds nothing.
 */
class VideoPosters extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ?VideoPosterBackfill $backfill = null,
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
		if ($this->backfill === null) {
			return;
		}

		try {
			$this->backfill->next();
		} catch (Throwable $e) {
			$this->logger?->warning('[Cron\\VideoPosters] the poster frames could not be made', [
				'exception' => $e,
			]);
		}
	}
}
