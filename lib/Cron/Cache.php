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
use OC\BackgroundJob\TimedJob;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\QueryException;


/**
 * Class Cache
 *
 * @package OCA\Social\Cron
 */
class Cache extends TimedJob {


	/** @var AccountService */
	private $accountService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var DocumentService */
	private $documentService;

	/** @var HashtagService */
	private $hashtagService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * Cache constructor.
	 */
	public function __construct() {
		$this->setInterval(12 * 60); // 12 minutes
	}


	/**
	 * @param mixed $argument
	 *
	 * @throws QueryException
	 */
	protected function run($argument) {
		$app = new Application();
		$c = $app->getContainer();

		$this->accountService = $c->query(AccountService::class);
		$this->cacheActorService = $c->query(CacheActorService::class);
		$this->documentService = $c->query(DocumentService::class);
		$this->hashtagService = $c->query(HashtagService::class);
		$this->configService = $c->query(ConfigService::class);
		$this->miscService = $c->query(MiscService::class);

		$this->manageCache();
	}


	private function manageCache() {
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
