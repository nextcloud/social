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
use OCA\Social\Service\MiscService;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class AccountCreate extends Base {


	/** @var IUserManager */
	private $userManager;

	/** @var AccountService */
	private $accountService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * CacheUpdate constructor.
	 *
	 * @param IUserManager $userManager
	 * @param AccountService $accountService
	 * @param CacheActorService $cacheActorService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
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


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:account:create')
			 ->addArgument('userId', InputArgument::REQUIRED, 'Nextcloud username of the account')
			 ->addOption('handle', '', InputOption::VALUE_REQUIRED, 'social handle')
			 ->setDescription('Create a new social account');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$userId = $input->getArgument('userId');

		if (($handle = $input->getOption('handle')) === null) {
			$handle = $userId;
		}

		if ($this->userManager->get($userId) === null) {
			throw new Exception('Unknown user');
		}

		$this->accountService->createActor($userId, $handle);
	}

}

