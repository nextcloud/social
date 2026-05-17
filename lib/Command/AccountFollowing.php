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
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MiscService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AccountFollowing extends Base {
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private FollowService $followService;
	private ConfigService $configService;
	private MiscService $miscService;

	public function __construct(
		AccountService $accountService, CacheActorService $cacheActorService,
		FollowService $followService, ConfigService $configService, MiscService $miscService,
	) {
		parent::__construct();

		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->followService = $followService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}

	protected function configure() {
		parent::configure();
		$this->setName('social:account:following')
			->addArgument('userId', InputArgument::REQUIRED, 'Nextcloud userid')
			->addArgument('account', InputArgument::REQUIRED, 'Account to follow')
			->addOption('local', '', InputOption::VALUE_NONE, 'account is local')
			->addOption('unfollow', '', InputOption::VALUE_NONE, 'unfollow')
			->setDescription('Following a new account');
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('userId');
		$account = $input->getArgument('account');

		$output->writeln('<info>Following account...</info>');
		$output->writeln('  User: ' . $userId);
		$output->writeln('  Account: ' . $account);

		try {
			$actor = $this->accountService->getActor($userId);
			$output->writeln('  Local actor: ' . $actor->getId() . ' (nid=' . $actor->getNid() . ')');
		} catch (\Exception $e) {
			$output->writeln('<error>Failed to get local actor: ' . $e->getMessage() . '</error>');
			return 1;
		}

		if ($input->getOption('local')) {
			try {
				$local = $this->cacheActorService->getFromLocalAccount($account);
				$account = $local->getAccount();
				$output->writeln('  Local account resolved to: ' . $account);
			} catch (\Exception $e) {
				$output->writeln('<error>Failed to resolve local account: ' . $e->getMessage() . '</error>');
				return 1;
			}
		}

		try {
			if ($input->getOption('unfollow')) {
				$output->writeln('  Unfollowing...');
				$this->followService->unfollowAccount($actor, $account);
				$output->writeln('<info>Unfollow request sent.</info>');
			} else {
				$output->writeln('  Following...');
				$this->followService->followAccount($actor, $account);
				$output->writeln('<info>Follow request sent successfully.</info>');
			}
		} catch (\Exception $e) {
			$output->writeln('<error>Failed: ' . get_class($e) . ': ' . $e->getMessage() . '</error>');
			return 1;
		}

		return 0;
	}
}
