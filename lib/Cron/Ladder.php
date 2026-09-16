<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\VideoLadderService;
use OCA\Social\Service\VideoLadderWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writing stored videos at a ladder of smaller sizes.
 *
 * Its own job rather than a step of `Cron\Transcode`, even though the two are
 * neighbours: a transcode is one encode and a ladder is three, so a job that
 * did both would have an unpredictable cost per run and the transcoder — the
 * one that decides whether a video travels at all — would be the thing waiting
 * behind it.
 *
 * **One video per run**, and a longer interval than the transcoder's, because
 * each run is several times the work. A hundred videos is a few days at this
 * rate, which is the right speed for something that makes existing uploads
 * nicer to watch and is not urgent for any of them.
 *
 * Off unless an administrator turned it on.
 */
class Ladder extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ?VideoLadderService $videoLadderService = null,
		private ?VideoLadderWorker $worker = null,
		private ?LoggerInterface $logger = null,
	) {
		parent::__construct($time);

		$this->setInterval(30 * 60);
	}

	/**
	 * @param mixed $argument
	 */
	#[\Override]
	protected function run($argument) {
		if ($this->worker === null || $this->videoLadderService === null) {
			return;
		}

		if (!$this->videoLadderService->isEnabled()) {
			return;
		}

		try {
			$this->worker->ladderNext();
		} catch (Throwable $e) {
			// one bad file must not stop the job from running again
			$this->logger?->warning('[Cron\\Ladder] a video could not be laddered', [
				'exception' => $e,
			]);
		}
	}
}
