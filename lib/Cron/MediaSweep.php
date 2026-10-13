<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Service\DocumentService;
use OCA\Social\Service\MediaPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The housekeeping stored media needs and nothing else does.
 *
 * Two passes, both bounded, both resumable by simply being run again — the
 * first page after page for SECONDS, the second one page:
 *
 * - the documents whose parent — the post, the cached actor or the story they
 *   hang off — no longer exists. Nothing can ever refer to those files again,
 *   and until the callers were taught to take the files with the rows, every
 *   deleted post and every expired story left its picture in appdata for good.
 * - the rows stored before their type was recorded. A client hides an
 *   attachment whose type it does not know, so those pictures are invisible
 *   until something says what they are, and nothing else ever will: the
 *   caching run only looks at rows with no local copy.
 *
 * Daily. Neither answer changes within the hour, the first is a delete per row
 * and the second is a read per row, and a cron slot is worth more to the
 * queue and the caches than to either of these.
 */
class MediaSweep extends TimedJob {
	private const INTERVAL = 86400;

	/**
	 * How old a row has to be before its missing parent is taken as final.
	 *
	 * Generous on purpose: an attachment is written during the import of the
	 * post it belongs to, which is to say before the post's own row exists,
	 * and a month of margin costs nothing but disk that was already spent.
	 */
	public const DAYS = 30;

	/** Rows per page, of each kind. */
	public const BATCH = 500;

	/**
	 * How long the orphan sweep reads on, page after page, in seconds: a
	 * prune or a purge orphans more than one page a day.
	 */
	public const SECONDS = 120;

	public function __construct(
		ITimeFactory $time,
		private MediaPurgeService $mediaPurgeService,
		private DocumentService $documentService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run($argument): void {
		foreach ([
			'sweepOrphans' => fn (): int => $this->mediaPurgeService->sweepOrphans(
				self::DAYS, self::BATCH, $this->time->getTime() + self::SECONDS
			),
			'fillMissingMediaTypes' => fn (): int => $this->documentService->fillMissingMediaTypes(self::BATCH),
		] as $step => $work) {
			try {
				$work();
			} catch (Throwable $e) {
				// a pass that fails is retried tomorrow; nothing here is
				// urgent and nothing here is lost by waiting
				$this->logger->warning('[Cron\\MediaSweep] step "' . $step . '" failed', [
					'step' => $step, 'exception' => $e,
				]);
			}
		}
	}
}
