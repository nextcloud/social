<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\GifPackService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetching the first screenful of the animated emoji before anybody asks.
 *
 * Without this the first person to open the picker on a new instance waits
 * while the server fetches sixty pictures it has never seen. With it, the
 * sixty the picker opens on are already in appdata by the time anybody looks.
 *
 * **Only that first screenful.** The other eight hundred are fetched when
 * somebody actually picks one, and not before: all of them together are
 * something like 440 MiB of appdata, which is not a thing to put on every
 * instance in the world on the chance that somebody wants a picture of a
 * hedgehog. What this warms is the part every instance is certain to show.
 *
 * A few at a time, because each one is an HTTP request out; the whole
 * screenful is warm after a handful of runs and the job then does nothing for
 * ever, at the cost of one appdata listing.
 */
class GifPack extends TimedJob {
	/** How much of the pack is worth having before it is asked for. */
	private const WARM = 60;

	/** How many to fetch in one run. */
	private const PER_RUN = 10;

	public function __construct(
		ITimeFactory $time,
		private ?GifPackService $gifPackService = null,
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
		if ($this->gifPackService === null || !$this->gifPackService->enabled()) {
			return;
		}

		$fetched = 0;
		foreach (array_slice($this->gifPackService->all(), 0, self::WARM) as $gif) {
			if ($fetched >= self::PER_RUN) {
				return;
			}

			if ($this->gifPackService->isCached($gif->getSlug())) {
				continue;
			}

			try {
				$this->gifPackService->file($gif->getSlug());
				$fetched++;
			} catch (Throwable $e) {
				// the source is unreachable, or this instance has no way out:
				// say so once and stop, rather than spending the run failing
				$this->logger?->notice('could not warm the animated emoji pack', [
					'slug' => $gif->getSlug(), 'exception' => $e,
				]);

				return;
			}
		}
	}
}
