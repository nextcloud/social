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


use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OC\Core\Command\Base;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PushService;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class CheckInstall extends Base {


	use TArrayTools;


	/** @var IUserManager */
	private $userManager;

	/** @var CheckService */
	private $checkService;

	/** @var */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * CacheUpdate constructor.
	 *
	 * @param IUserManager $userManager
	 * @param CheckService $checkService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 * @param PushService $pushService
	 */
	public function __construct(
		IUserManager $userManager, CheckService $checkService, ConfigService $configService,
		MiscService $miscService, PushService $pushService
	) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->checkService = $checkService;
		$this->configService = $configService;
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
				 'push', '', InputOption::VALUE_REQUIRED,
				 'a local account used to test integration to Nextcloud Push',
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
		$result = $this->checkService->checkInstallationStatus();

		if ($this->checkPushApp($input, $output)) {
			return;
		}

		$output->writeln('- ' . $this->getInt('invalidFollowers', $result, 0) . ' invalid followers removed');
		$output->writeln('- ' . $this->getInt('invalidNotes', $result, 0) . ' invalid notes removed');

		$output->writeln('');
		$output->writeln('- Your current configuration: ');
		$output->writeln(json_encode($this->configService->getConfig(), JSON_PRETTY_PRINT));
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return bool
	 * @throws Exception
	 */
	private function checkPushApp(InputInterface $input, OutputInterface $output): bool {
		$userId = $input->getOption('push');
		if ($userId === '') {
			return false;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new Exception('unknown user');
		}

		$wrapper = $this->pushService->testOnAccount($userId);

		$output->writeln(json_encode($wrapper, JSON_PRETTY_PRINT));

		return true;
	}

}

