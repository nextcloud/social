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
use OCA\Social\Service\CheckService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PushService;
use OCP\IUserManager;
use OCP\Stratos\Exceptions\StratosInstallException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class CheckInstall extends Base {


	/** @var IUserManager */
	private $userManager;

	/** @var CheckService */
	private $checkService;

	/** @var MiscService */
	private $miscService;


	/**
	 * CacheUpdate constructor.
	 *
	 * @param IUserManager $userManager
	 * @param CheckService $checkService
	 * @param MiscService $miscService
	 * @param PushService $pushService
	 */
	public function __construct(
		IUserManager $userManager, CheckService $checkService, MiscService $miscService,
		PushService $pushService
	) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->checkService = $checkService;
		$this->miscService = $miscService;
		$this->pushService = $pushService;
	}

	/** @var PushService */
	private $pushService;

	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:check:install')
			 ->addOption(
				 'stratos', '', InputOption::VALUE_REQUIRED, 'a local account used to test Stratos',
				 ''
			 )
			 ->setDescription('Check the integrity of the installation');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->checkService->checkInstallationStatus();

		$this->checkStratos($input, $output);
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	private function checkStratos(InputInterface $input, OutputInterface $output) {
		$userId = $input->getOption('stratos');
		if ($userId !== '') {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				throw new Exception('unknown user');
			}

			$wrapper = $this->pushService->testOnAccount($userId);

			$output->writeln(json_encode($wrapper, JSON_PRETTY_PRINT));
		}
	}

}

