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
use OCA\Social\Service\AccountFinder;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowOption;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MiscService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AccountFollowing extends Base {
	private AccountFinder $accountFinder;
	private CacheActorService $cacheActorService;
	private FollowService $followService;

	public function __construct(
		AccountFinder $accountFinder, FollowService $followService, ConfigService $configService
	) {
		parent::__construct();

		$this->accountFinder = $accountFinder;
		$this->followService = $followService;
		$this->configService = $configService;
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
	protected function execute(InputInterface $input, OutputInterface $output) {
		$userId = $input->getArgument('userId');
		$account = $input->getArgument('account');

		$sourceAccount = $this->accountFinder->getAccountByNextcloudId($userId);

		if ($input->getOption('local')) {
			$targetAccount = $this->accountFinder->getAccountByNextcloudId($account);
		}

		if ($input->getOption('unfollow')) {
			$this->followService->unfollow($sourceAccount, $targetAccount, FollowOption::default());
		} else {
			$this->followService->follow($sourceAccount, $targetAccount, FollowOption::default());
		}
	}
}
