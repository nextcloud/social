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
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class Cache extends TimedJob {
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private DocumentService $documentService;
	private HashtagService $hashtagService;
	private StreamService $streamService;
	private CacheActorsRequest $cacheActorsRequest;

	public function __construct(
		ITimeFactory $time,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		DocumentService $documentService,
		HashtagService $hashtagService,
		StreamService $streamService,
		CacheActorsRequest $cacheActorsRequest,
	) {
		parent::__construct($time);
		$this->setInterval(12 * 60);
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->documentService = $documentService;
		$this->hashtagService = $hashtagService;
		$this->streamService = $streamService;
		$this->cacheActorsRequest = $cacheActorsRequest;
	}

	protected function run($argument) {
		try {
		} catch (Exception $e) {
		}

		try {
			$this->accountService->manageDeletedActors();
		} catch (Exception $e) {
		}

		try {
			$this->accountService->manageCacheLocalActors();
		} catch (Exception $e) {
		}

		try {
			$this->cacheActorService->manageCacheRemoteActors();
		} catch (Exception $e) {
		}

		try {
			$this->cacheActorService->manageDetailsRemoteActors();
		} catch (Exception $e) {
		}

		try {
			$this->documentService->manageCacheDocuments();
		} catch (Exception $e) {
		}

		try {
			$this->hashtagService->manageHashtags();
		} catch (Exception $e) {
		}

		// Sync timelines of cached remote actors
		try {
			$remoteActors = $this->cacheActorsRequest->getRemoteActorsToUpdate(false);
			foreach ($remoteActors as $actor) {
				try {
					$this->streamService->syncRemoteTimeline($actor);
				} catch (Exception $e) {
				}
			}
		} catch (Exception $e) {
		}
	}
}
