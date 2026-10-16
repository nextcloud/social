<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Gives the direct messages stored before `social_stream.root_prim` existed
 * the root of their thread.
 *
 * Queued once by `Version1000Date20261016100400`, so — like `SearchIndex` —
 * it is not registered in `info.xml`. It reads `social_stream` in nid order
 * from the cursor it keeps in the app settings, a chunk at a time and for a
 * bounded time per run (`ConversationsRequest::backfillRoots()`), and puts
 * itself back on the list until a chunk comes back short: every direct
 * message stored since has its root from the moment it was stored. A message
 * that already has one is passed over, so a run repeated after a failure
 * changes nothing it already did; one that fails is logged and passed over.
 */
class ThreadRoots extends QueuedJob {
	/** Where the job has got to, as a nid. */
	public const CURSOR = 'thread_roots_cursor';

	/** Posts read per chunk, direct messages or not. */
	public const CHUNK = 2000;

	/** How long one run may keep going, in seconds: a cron slot is shared. */
	public const SECONDS_PER_RUN = 60;

	public function __construct(
		ITimeFactory $time,
		private ConversationsRequest $conversationsRequest,
		private ConfigService $configService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$deadline = $this->time->getTime() + self::SECONDS_PER_RUN;
		$cursor = $this->configService->getAppValue(self::CURSOR);
		$cursor = ctype_digit($cursor) ? $cursor : '0';

		try {
			do {
				$read = $this->conversationsRequest->backfillRoots($cursor, self::CHUNK);
				if ($read['read'] > 0) {
					$cursor = $read['last'];
					$this->configService->setAppValue(self::CURSOR, $cursor);
				}

				if ($read['read'] < self::CHUNK) {
					$this->logger->info('[Cron\\ThreadRoots] every stored direct message has its thread root');

					return;
				}
			} while ($this->time->getTime() < $deadline);
		} catch (\Throwable $e) {
			$this->logger->warning('[Cron\\ThreadRoots] could not store the thread roots after ' . $cursor
				. '; the next run goes on from there', ['exception' => $e]);
		}

		$this->jobList->add(self::class);
	}
}
