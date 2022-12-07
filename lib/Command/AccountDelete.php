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
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCP\IUserManager;
use OCP\Server;
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

		// TODO: broadcast to other instance
		throw new Exception('not fully available');
		
		$actor = $this->cacheActorService->getFromLocalAccount($account);
		$personInterface = Server::get(PersonInterface::class);
		$personInterface->deleteActor($actor->getId());

		return 0;
	}
}
