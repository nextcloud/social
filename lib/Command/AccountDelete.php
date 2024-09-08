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
use OCA\Social\Service\ConfigService;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AccountDelete extends Base {
	private IUserManager $userManager;
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private ConfigService $configService;

	public function __construct(
		IUserManager $userManager,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		ConfigService $configService
	) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
	}

	protected function configure(): void {
		parent::configure();
		$this->setName('social:account:delete')
			 ->addArgument('account', InputArgument::REQUIRED, 'Social Local Account')
			 ->setDescription('Delete a local social account');
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$account = $input->getArgument('account');

		$this->accountService->deleteActor($account);

		return 0;
	}
}
