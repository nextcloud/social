<?php

declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Social\Cron;

use Exception;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\HashtagService;
use OCP\AppFramework\QueryException;

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
			$this->accountService->blindKeyRotation();
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
			$this->documentService->manageCacheDocuments();
		} catch (Exception $e) {
		}

		try {
			$this->hashtagService->manageHashtags();
		} catch (Exception $e) {
		}
	}
}
