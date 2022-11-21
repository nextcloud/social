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
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Service\FediverseService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Fediverse
 *
 * @package OCA\Social\Command
 */
class Fediverse extends Base {
	private FediverseService $fediverseService;
	private ?OutputInterface $output = null;

	public function __construct(FediverseService $fediverseService) {
		parent::__construct();
		$this->fediverseService = $fediverseService;
	}

	protected function configure() {
		parent::configure();
		$this->setName('social:fediverse')
			 ->addOption(
				'type', 't', InputArgument::OPTIONAL,
				'Change the type of access management', ''
			 )
			 ->addArgument('action', InputArgument::OPTIONAL, 'add/remove/test address', '')
			 ->addArgument('address', InputArgument::OPTIONAL, 'address/host', '')
			 ->setDescription('Allow or deny access to the fediverse');
	}

	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->output = $output;

		if ($this->typeAccess($input->getOption('type'))) {
			return 0;
		}

		$this->output->writeln(
			'Current access type: <info>' . $this->fediverseService->getAccessType() . '</info>'
		);

		switch ($input->getArgument('action')) {
			case '':
				$this->listAddresses(false);
				break;

			case 'list':
				$this->listAddresses(true);
				break;

			case 'add':
				$this->addAddress($input->getArgument('address'));
				break;

			case 'remove':
				$this->removeAddress($input->getArgument('address'));
				break;

			case 'test':
				$this->testAddress($input->getArgument('address'));
				break;

			case 'reset':
				$this->resetAddresses();
				break;

			default:
				throw new Exception('specify action: add, remove, list, reset');
		}

		return 0;
	}

	/**
	 * @throws Exception
	 */
	private function typeAccess(string $type): bool {
		if ($type === '') {
			return false;
		}

		$this->fediverseService->setAccessType($type);

		return true;
	}

	private function listAddresses(bool $allKnownAddress = false): void {
		if ($allKnownAddress) {
			$this->output->writeln('- Known address:');
			foreach ($this->fediverseService->getKnownAddresses() as $address) {
				$this->output->writeln('  <info>' . $address . '</info>');
			}
		}

		$this->output->writeln('- List:');
		foreach ($this->fediverseService->getListedAddresses() as $address) {
			$this->output->writeln('  <info>' . $address . '</info>');
		}
	}


	/**
	 * @throws Exception
	 */
	private function addAddress(string $address): void {
		$this->fediverseService->addAddress($address);
		$this->output->writeln('<info>' . $address . '</info> added to the list');
	}

	/**
	 * @throws Exception
	 */
	private function removeAddress(string $address): void {
		$this->fediverseService->removeAddress($address);
		$this->output->writeln('<info>' . $address . '</info> removed from the list');
	}

	/**
	 * @throws SocialAppConfigException
	 */
	private function testAddress(string $address) {
		try {
			$this->fediverseService->authorized($address);
			$this->output->writeln('<info>Authorized</info>');
		} catch (UnauthorizedFediverseException $e) {
			$this->output->writeln('<comment>Unauthorized</comment>');
		}
	}

	private function resetAddresses() {
		$this->fediverseService->resetAddresses();
		$this->output->writeln('list is now empty');
	}
}
