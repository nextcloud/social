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
use OCA\Social\Service\MiscService;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AccountCreate extends Base {
	private IUserManager $userManager;
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private ConfigService $configService;
	private MiscService $miscService;

	public function __construct(
		IUserManager $userManager, AccountService $accountService,
		CacheActorService $cacheActorService, ConfigService $configService, MiscService $miscService
	) {
		parent::__construct();

		$this->userManager = $userManager;

		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}

	protected function configure() {
		parent::configure();
		$this->setName('social:account:create')
			 ->addArgument('userId', InputArgument::REQUIRED, 'Nextcloud username of the account')
			 ->addOption('handle', '', InputOption::VALUE_REQUIRED, 'social handle')
			 ->setDescription('Create a new social account');
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('userId');

		if (($handle = $input->getOption('handle')) === null) {
			$handle = $userId;
		}

		if ($this->userManager->get($userId) === null) {
			throw new Exception('Unknown user');
		}

		$this->accountService->createActor($userId, $handle);

		return 0;
	}
}
