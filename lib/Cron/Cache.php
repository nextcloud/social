<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Cron;

use Exception;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\HashtagService;
use OCP\AppFramework\QueryException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Class Cache
 *
 * @package OCA\Social\Cron
 */
class Cache extends TimedJob {
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private DocumentService $documentService;
	private HashtagService $hashtagService;

	public function __construct(ITimeFactory $time, AccountService $accountService, CacheActorService $cacheActorService, DocumentService $documentService, HashtagService $hashtagService) {
		parent::__construct($time);
		$this->setInterval(12 * 60); // 12 minutes
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->documentService = $documentService;
		$this->hashtagService = $hashtagService;
	}

	/**
	 * @param mixed $argument
	 *
	 * @throws QueryException
	 */
	protected function run($argument) {
		try {
			//			$this->accountService->blindKeyRotation();
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
	}
}
