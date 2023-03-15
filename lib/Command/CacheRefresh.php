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
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\HashtagService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CacheRefresh extends Base {
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private DocumentService $documentService;
	private HashtagService $hashtagService;

	public function __construct(
		AccountService $accountService, CacheActorService $cacheActorService,
		DocumentService $documentService, HashtagService $hashtagService
	) {
		parent::__construct();

		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->documentService = $documentService;
		$this->hashtagService = $hashtagService;
	}

	protected function configure() {
		parent::configure();
		$this->setName('social:cache:refresh')
			 ->setDescription('Update the cache')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'enforce update of cached account');
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
//		$result = $this->accountService->blindKeyRotation();
//		$output->writeLn($result . ' key pairs refreshed');

		$result = $this->accountService->manageDeletedActors();
		$output->writeLn($result . ' local accounts deleted');

		$result = $this->accountService->manageCacheLocalActors();
		$output->writeLn($result . ' local accounts regenerated');

		$result = $this->cacheActorService->missingCacheRemoteActors();
		$output->writeLn($result . ' remote accounts created');

		$result = $this->cacheActorService->manageCacheRemoteActors($input->getOption('force'));
		$output->writeLn($result . ' remote accounts updated');

		$result = $this->documentService->manageCacheDocuments();
		$output->writeLn($result . ' documents cached');

		$result = $this->hashtagService->manageHashtags();
		$output->writeLn($result . ' hashtags updated');

		return 0;
	}
}
