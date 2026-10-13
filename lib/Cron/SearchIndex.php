<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Adds the posts stored before `social_search_term` existed to it.
 *
 * Queued once by `Version1000Date20261008000401`, so — like `DomainPurge` —
 * it is not registered in `info.xml`. It walks `social_stream` in nid order
 * from the cursor it keeps in the app settings, a chunk at a time and for a
 * bounded time per run, and puts itself back on the list until a chunk comes
 * back short: that is the newest post, and every post stored since the table
 * existed was indexed when it was stored. It then marks the index ready and
 * is not queued again; the search stops scanning from that request on.
 *
 * A run that fails keeps the cursor of the last chunk it finished, so the
 * next run repeats one chunk at most, and the unique (term, nid) index makes
 * the repeat harmless. A chunk that fails again and again is not repeated
 * for ever: after FAILURES_BEFORE_ISOLATING failures at the same cursor the
 * posts are indexed one at a time, and the one post a chunk of one fails on
 * is named in the log, counted in `search_index_skipped` and passed over.
 * Every failed run changes what the next one does, so no post can hold the
 * index back indefinitely.
 */
class SearchIndex extends QueuedJob {
	/** Posts per chunk; each chunk's words go in as a few multi-row inserts. */
	public const CHUNK = 500;

	/** How long one run may keep going, in seconds: a cron slot is shared. */
	public const SECONDS_PER_RUN = 60;

	/**
	 * How often a chunk may fail at one cursor before its posts are indexed
	 * one at a time, so the one that fails can be named and skipped. One
	 * failure is retried whole: a database that went away comes back.
	 */
	public const FAILURES_BEFORE_ISOLATING = 2;

	public function __construct(
		ITimeFactory $time,
		private SearchTermsRequest $searchTermsRequest,
		private ConfigService $configService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		if ($this->searchTermsRequest->isReady()) {
			return;
		}

		$deadline = $this->time->getTime() + self::SECONDS_PER_RUN;
		$cursor = $this->configService->getAppValue(SearchTermsRequest::CURSOR);
		$cursor = ctype_digit($cursor) ? $cursor : '0';
		$chunk = ($this->failuresAt($cursor) >= self::FAILURES_BEFORE_ISOLATING) ? 1 : self::CHUNK;

		try {
			do {
				$read = $this->searchTermsRequest->backfill($cursor, $chunk);
				$cursor = $read['last'];
				$this->configService->setAppValue(SearchTermsRequest::CURSOR, $cursor);

				if ($read['read'] < $chunk) {
					$this->configService->setAppValue(SearchTermsRequest::READY, '1');
					$this->configService->deleteAppValue(SearchTermsRequest::FAILING);
					$this->logger->info('[Cron\\SearchIndex] every stored post is in the search index');

					return;
				}
			} while ($this->time->getTime() < $deadline);
		} catch (\Throwable $e) {
			$this->failed($cursor, $chunk, $e);
		}

		$this->jobList->add(self::class);
	}

	/** How often the chunk after `$cursor` has failed so far. */
	private function failuresAt(string $cursor): int {
		[$failingCursor, $count] = array_pad(
			explode(':', $this->configService->getAppValue(SearchTermsRequest::FAILING)), 2, '0'
		);

		return ($failingCursor === $cursor) ? (int)$count : 0;
	}

	/**
	 * Records a failed chunk so the next run does something different: the
	 * same chunk again, then the posts one at a time, and a chunk of one that
	 * fails is the one post that cannot be indexed, which is passed over.
	 */
	private function failed(string $cursor, int $chunk, \Throwable $e): void {
		$context = ['exception' => $e, 'cursor' => $cursor];
		$poison = '';
		if ($chunk === 1) {
			try {
				$poison = $this->searchTermsRequest->nextNid($cursor);
			} catch (\Throwable $again) {
				// the database is what is failing; counted below, retried next run
			}
		}

		if ($poison !== '') {
			$this->configService->setAppValue(SearchTermsRequest::CURSOR, $poison);
			$this->configService->deleteAppValue(SearchTermsRequest::FAILING);
			$this->configService->setAppValue(
				SearchTermsRequest::SKIPPED, (string)($this->configService->getAppValueInt(SearchTermsRequest::SKIPPED) + 1)
			);
			$this->logger->warning('[Cron\\SearchIndex] skipping post ' . $poison
				. ': it cannot be indexed and is left out of the content search', $context + ['nid' => $poison]);

			return;
		}

		$count = $this->failuresAt($cursor) + 1;
		$this->configService->setAppValue(SearchTermsRequest::FAILING, $cursor . ':' . $count);
		$this->logger->warning('[Cron\\SearchIndex] could not index the posts after ' . $cursor
			. ' (attempt ' . $count . '); the search keeps scanning recent posts until a later run gets past them',
			$context);
	}
}
