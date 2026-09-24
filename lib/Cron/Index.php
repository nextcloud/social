<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\IndexService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Incrementally repairs old/missing stream side-index rows without truncation.
 */
class Index extends TimedJob {
	private const INTERVAL = 5 * 60;

	public function __construct(
		ITimeFactory $time,
		private IndexService $indexService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		try {
			$processed = $this->indexService->repairNextChunk();
			if ($processed > 0) {
				$this->logger->info('[Cron\\Index] repaired stream side indexes', [
					'count' => $processed,
				]);
			}
		} catch (Throwable $e) {
			// A failed pass is retried by the next scheduled run; the saved cursor
			// remains at the last completely indexed stream.
			$this->logger->error('[Cron\\Index] repair pass failed', ['exception' => $e]);
		}
	}
}
