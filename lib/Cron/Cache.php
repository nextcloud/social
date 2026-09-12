<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use Exception;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\StreamPruneService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class Cache extends TimedJob {
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private DocumentService $documentService;
	private HashtagService $hashtagService;
	private StreamService $streamService;
	private StreamPruneService $streamPruneService;
	private CacheActorsRequest $cacheActorsRequest;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		DocumentService $documentService,
		HashtagService $hashtagService,
		StreamService $streamService,
		StreamPruneService $streamPruneService,
		CacheActorsRequest $cacheActorsRequest,
		private PollService $pollService,
		LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(12 * 60);
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->documentService = $documentService;
		$this->hashtagService = $hashtagService;
		$this->streamService = $streamService;
		$this->streamPruneService = $streamPruneService;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->logger = $logger;
	}

	#[\Override]
	protected function run($argument) {
		$this->step('manageDeletedActors', function (): void {
			$this->accountService->manageDeletedActors();
		});

		$this->step('manageCacheLocalActors', function (): void {
			$this->accountService->manageCacheLocalActors();
		});

		$this->step('manageCacheRemoteActors', function (): void {
			$this->cacheActorService->manageCacheRemoteActors();
		});

		$this->step('manageDetailsRemoteActors', function (): void {
			$this->cacheActorService->manageDetailsRemoteActors();
		});

		$this->step('manageCacheDocuments', function (): void {
			$this->documentService->manageCacheDocuments();
		});

		$this->step('manageHashtags', function (): void {
			$this->hashtagService->manageHashtags();
		});

		$this->step('announceClosedPolls', function (): void {
			// a poll closes by its end time passing, so nothing happens at the
			// moment it does and something has to look
			$this->pollService->announceClosedPolls();
		});

		$this->step('prune', function (): void {
			// bounded per run so retention never dominates a cron slot
			$this->streamPruneService->prune(null, false, 5000);
		});

		$this->step('syncRemoteTimelines', function (): void {
			$this->syncRemoteTimelines();
		});
	}

	/**
	 * Runs one step of the cron and keeps going if it fails.
	 *
	 * At `warning`, not `debug`: Nextcloud's default loglevel is 2, so a
	 * `debug` line is written on no default instance, and the four cron steps
	 * that keep the caches alive used to fail invisibly. The step is named,
	 * because seven identical messages could not tell an administrator which
	 * one broke.
	 */
	private function step(string $step, callable $work): void {
		try {
			$work();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[Cron\\Cache] step "' . $step . '" failed: ' . $e->getMessage(),
				['exception' => $e, 'step' => $step]
			);
		}
	}

	/**
	 * One unreachable remote instance must not stop the sync of the others,
	 * so each actor is caught on its own — but it is still logged, with the
	 * actor it happened on.
	 */
	private function syncRemoteTimelines(): void {
		foreach ($this->cacheActorsRequest->getRemoteActorsToUpdate(false) as $actor) {
			try {
				$this->streamService->syncRemoteTimeline($actor);
			} catch (Exception $e) {
				$this->logger->warning(
					'[Cron\\Cache] could not sync the timeline of ' . $actor->getId()
					. ': ' . $e->getMessage(),
					['exception' => $e, 'step' => 'syncRemoteTimelines', 'actor' => $actor->getId()]
				);
			}
		}
	}
}
