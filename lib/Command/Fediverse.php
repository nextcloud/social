<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
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
class Fediverse extends SocialCommand {
	private const MAX_IMPORT_DOMAINS = 10000;

	private FediverseService $fediverseService;
	private ?OutputInterface $output = null;

	public function __construct(FediverseService $fediverseService) {
		parent::__construct();
		$this->fediverseService = $fediverseService;
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:fediverse')
			->addOption(
				'type', 't', InputArgument::OPTIONAL,
				'Change the type of access management', ''
			)
			->addArgument('action', InputArgument::OPTIONAL, 'add/remove/import/test/silence/unsilence/silenced address', '')
			->addArgument('address', InputArgument::OPTIONAL, 'address/host or CSV file for import', '')
			->setDescription('Allow or deny access to the fediverse');
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
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

			case 'import':
				return $this->importAddresses($input->getArgument('address'));
			case 'remove':
				$this->removeAddress($input->getArgument('address'));
				break;

			case 'test':
				$this->testAddress($input->getArgument('address'));
				break;

			case 'reset':
				$this->resetAddresses();
				break;

			case 'silence':
				$this->silenceAddress($input->getArgument('address'));
				break;

			case 'unsilence':
				$this->unsilenceAddress($input->getArgument('address'));
				break;

			case 'silenced':
				$this->listSilenced();
				break;

			default:
				throw new Exception(
					'specify action: add, remove, import, list, reset, silence, unsilence, silenced'
				);
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

	/**
	 * Silencing, which is the tier between blocking an instance and doing
	 * nothing about it: its accounts leave the public and global timelines and
	 * stay readable by whoever follows them, and nothing is deleted.
	 */
	private function silenceAddress(string $address): void {
		if ($address === '') {
			throw new Exception('specify an address to silence');
		}

		$this->fediverseService->silenceAddress($address);
		$this->output->writeln(
			'<info>' . $address . '</info> is silenced: out of the public and global timelines,'
			. ' still readable by the people who follow it.'
		);
	}

	/** Lifts a silence. Nothing was deleted, so everything comes back. */
	private function unsilenceAddress(string $address): void {
		if ($address === '') {
			throw new Exception('specify an address to unsilence');
		}

		$this->fediverseService->unsilenceAddress($address);
		$this->output->writeln('<info>' . $address . '</info> is no longer silenced.');
	}

	private function listSilenced(): void {
		$silenced = $this->fediverseService->getSilencedAddresses();
		if ($silenced === []) {
			$this->output->writeln('- No silenced instance.');

			return;
		}

		$this->output->writeln('- Silenced instances:');
		foreach ($silenced as $address) {
			$this->output->writeln('  <info>' . $address . '</info>');
		}
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
	 * Import the domain column from a CSV export into an administrator's
	 * existing block list. No external list is fetched or enabled implicitly.
	 */
	private function importAddresses(string $filePath): int {
		if ($this->fediverseService->getAccessType() !== 'all_but') {
			$this->output->writeln('<error>CSV imports require blocklist mode (all_but); the access mode was not changed.</error>');

			return 1;
		}

		if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
			$this->output->writeln('<error>Provide a readable CSV file.</error>');

			return 1;
		}

		try {
			$file = new \SplFileObject($filePath, 'r');
		} catch (\RuntimeException $e) {
			$this->output->writeln('<error>Could not open the CSV file: ' . $e->getMessage() . '</error>');

			return 1;
		}

		$domains = [];
		$firstRecord = true;
		$recordNumber = 0;
		while (!$file->eof()) {
			$row = $file->fgetcsv(',', '"', '');
			if ($row === false || (count($row) === 1 && trim((string)$row[0]) === '')) {
				continue;
			}

			$recordNumber++;
			$domain = trim($row[0] ?? '');
			if ($firstRecord) {
				$domain = preg_replace('/^\xEF\xBB\xBF/', '', $domain) ?? $domain;
				$firstRecord = false;
				if (in_array(strtolower($domain), ['#domain', 'domain', 'host', 'hostname'], true)) {
					continue;
				}
			}

			if ($domain === '' || str_starts_with($domain, '#')) {
				continue;
			}

			$domain = rtrim(strtolower($domain), '.');
			if (!$this->isImportableDomain($domain)) {
				$this->output->writeln(
					'<error>Invalid domain in CSV record ' . $recordNumber
					. '. No domains were imported.</error>'
				);

				return 1;
			}

			$domains[$domain] = true;
			if (count($domains) > self::MAX_IMPORT_DOMAINS) {
				$this->output->writeln(
					'<error>The CSV contains more than ' . self::MAX_IMPORT_DOMAINS
					. ' unique domains. No domains were imported.</error>'
				);

				return 1;
			}
		}

		if ($domains === []) {
			$this->output->writeln('<error>The CSV did not contain any domains.</error>');

			return 1;
		}

		$imported = $this->fediverseService->addAddresses(array_keys($domains));
		$alreadyListed = count($domains) - $imported;
		$this->output->writeln(
			'<info>Imported ' . $imported . ' domains; ' . $alreadyListed
			. ' were already listed. Review the source policy before each import.</info>'
		);

		return 0;
	}

	private function isImportableDomain(string $domain): bool {
		if ($domain === '' || strlen($domain) > 253) {
			return false;
		}

		$label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

		return preg_match('/^(?:' . $label . ')(?:\.(?:' . $label . '))*$/D', $domain) === 1;
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
