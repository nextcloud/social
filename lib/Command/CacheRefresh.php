<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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

		$result = $this->cacheActorService->manageDetailsRemoteActors($input->getOption('force'));
		$output->writeLn($result . ' remote accounts details updated');

		$result = $this->documentService->manageCacheDocuments();
		$output->writeLn($result . ' documents cached');

		$result = $this->hashtagService->manageHashtags();
		$output->writeLn($result . ' hashtags updated');

		return 0;
	}
}
