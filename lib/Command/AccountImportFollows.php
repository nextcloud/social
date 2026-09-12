<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Service\MigrationService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-creates the follows of a Mastodon `following_accounts.csv` from a local
 * account: the other half of moving an account here.
 */
class AccountImportFollows extends SocialCommand {
	public function __construct(
		private MigrationService $migrationService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:account:import-follows')
			->addArgument('userId', InputArgument::REQUIRED, 'Nextcloud user whose actor follows the accounts')
			->addArgument(
				'csv', InputArgument::REQUIRED,
				'path to a Mastodon following_accounts.csv (header "Account address,…", or one handle per line)'
			)
			->setDescription('Follow every account of a Mastodon following_accounts.csv export');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('userId');
		$path = (string)$input->getArgument('csv');

		$csv = is_file($path) ? file_get_contents($path) : false;
		if ($csv === false) {
			$output->writeln('<error>cannot read ' . $path . '</error>');

			return 1;
		}

		$handles = MigrationService::parseFollowsCsv($csv);
		$output->writeln('Following ' . count($handles) . ' account(s) as ' . $userId . '...');

		try {
			$result = $this->migrationService->importFollows($userId, $csv);
		} catch (Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln('<info>' . $result['followed'] . '</info> followed (or already followed)');
		$output->writeln($result['skipped'] . ' skipped (the importing account itself)');
		$output->writeln(count($result['failed']) . ' failed');
		foreach ($result['failed'] as $handle => $reason) {
			$output->writeln('  - <comment>' . $handle . '</comment>: ' . $reason);
		}

		// something was asked for and none of it happened
		return ($result['followed'] === 0 && $result['failed'] !== []) ? 1 : 0;
	}
}
