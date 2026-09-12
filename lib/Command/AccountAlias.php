<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OC\Core\Command\Base;
use OCA\Social\Service\MigrationService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `alsoKnownAs` list of a local actor: the accounts it also answers to.
 *
 * A remote server accepts a Move *into* here only when the actor here lists
 * the account that is moving, so this is the first step of bringing an
 * account over from elsewhere.
 */
class AccountAlias extends Base {
	public function __construct(
		private MigrationService $migrationService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:account:alias')
			->addArgument('userId', InputArgument::REQUIRED, 'Nextcloud user whose actor is aliased')
			->addOption(
				'add', '', InputOption::VALUE_REQUIRED,
				'actor id (https://… address, not the handle) to add to alsoKnownAs'
			)
			->addOption(
				'remove', '', InputOption::VALUE_REQUIRED,
				'actor id to remove from alsoKnownAs'
			)
			->addOption('list', '', InputOption::VALUE_NONE, 'print the current list (the default)')
			->setDescription('Manage the alsoKnownAs aliases of a local account');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('userId');
		$add = (string)$input->getOption('add');
		$remove = (string)$input->getOption('remove');

		if ($add !== '' && $remove !== '') {
			$output->writeln('<error>--add and --remove cannot be combined; run them one after the other.</error>');

			return 1;
		}

		try {
			if ($add !== '') {
				$aliases = $this->migrationService->addAlias($userId, $add);
				$output->writeln('<info>added</info> ' . $add);
			} elseif ($remove !== '') {
				$aliases = $this->migrationService->removeAlias($userId, $remove);
				$output->writeln('<info>removed</info> ' . $remove);
			} else {
				$aliases = $this->migrationService->listAliases($userId);
			}
		} catch (Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		if ($aliases === []) {
			$output->writeln('no alias set.');

			return 0;
		}

		$output->writeln('alsoKnownAs:');
		foreach ($aliases as $alias) {
			$output->writeln('  - ' . $alias);
		}

		return 0;
	}
}
