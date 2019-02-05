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


namespace OCA\Social\Command;


use Exception;
use OC\Core\Command\Base;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\MiscService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class CacheRefresh extends Base {


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
	 * CacheUpdate constructor.
	 *
	 * @param AccountService $accountService
	 * @param CacheActorService $cacheActorService
	 * @param DocumentService $documentService
	 * @param HashtagService $hashtagService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		AccountService $actorService, CacheActorService $cacheActorService,
		DocumentService $documentService, HashtagService $hashtagService,
		ConfigService $configService, MiscService $miscService
	) {
		parent::__construct();

		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->documentService = $documentService;
		$this->hashtagService = $hashtagService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:cache:refresh')
			 ->setDescription('Update the cache');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {

		$result = $this->accountService->blindKeyRotation();
		$output->writeLn($result . ' key pairs refreshed');

		$result = $this->accountService->manageCacheLocalActors();
		$output->writeLn($result . ' local accounts regenerated');

		$result = $this->cacheActorService->missingCacheRemoteActors();
		$output->writeLn($result . ' remote accounts created');

		$result = $this->cacheActorService->manageCacheRemoteActors();
		$output->writeLn($result . ' remote accounts updated');

		$result = $this->documentService->manageCacheDocuments();
		$output->writeLn($result . ' documents cached');

		$result = $this->hashtagService->manageHashtags();
		$output->writeLn($result . ' hashtags updated');
	}


}

