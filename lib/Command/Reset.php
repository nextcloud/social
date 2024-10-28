<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OC\Core\Command\Base;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class Reset extends Base {
	private CoreRequestBuilder $coreRequestBuilder;

	private CheckService $checkService;

	private ConfigService $configService;

	private MiscService $miscService;


	/**
	 * CacheUpdate constructor.
	 *
	 * @param CoreRequestBuilder $coreRequestBuilder
	 * @param CheckService $checkService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		CoreRequestBuilder $coreRequestBuilder, CheckService $checkService, ConfigService $configService,
		MiscService $miscService,
	) {
		parent::__construct();

		$this->checkService = $checkService;
		$this->coreRequestBuilder = $coreRequestBuilder;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:reset')
			->addOption('uninstall', '', InputOption::VALUE_NONE, 'full removing of the app')
			->setDescription('Reset ALL data related to the Social App');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$helper = $this->getHelper('question');
		$output->writeln(
			'<error>Beware, this operation will delete all content from the Social App.</error>'
		);
		$output->writeln('');
		$question = new ConfirmationQuestion(
			'<info>Do you confirm this operation?</info> (y/N) ', false, '/^(y|Y)/i'
		);

		if (!$helper->ask($input, $output, $question)) {
			return 0;
		}

		$question = new ConfirmationQuestion(
			'<info>Operation is destructive. Are you sure about this?</info> (y/N) ', false,
			'/^(y|Y)/i'
		);
		if (!$helper->ask($input, $output, $question)) {
			return 0;
		}


		if ($input->getOption('uninstall')) {
			try {
				$output->writeln('');
				$output->write('Uninstalling Social App...');
				$this->fullUninstall($output);
				$output->writeln('<info>uninstalled</info>');
			} catch (Exception $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
			}

			return 0;
		}


		$output->writeln('');
		$output->write('flushing data... ');
		try {
			$this->coreRequestBuilder->emptyAll();
			$output->writeln('<info>done</info>');
		} catch (Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 0;
		}

		$this->checkService->checkInstallationStatus(true);
		$output->writeln('');

		$cloudAddress = $this->configService->getCloudUrl();
		$question = new Question(
			'<info>Now is a good time to change the base address of your cloud: </info> ('
			. $cloudAddress . ') ',
			$cloudAddress
		);

		$newCloudAddress = $helper->ask($input, $output, $question);

		if ($newCloudAddress === $cloudAddress) {
			return 0;
		}

		$this->configService->setCloudUrl($newCloudAddress);

		$output->writeln('');
		$output->writeln('New address: <info>' . $newCloudAddress . '</info>');

		return 0;
	}


	/**
	 * @param OutputInterface $output
	 */
	private function fullUninstall(OutputInterface $output) {
		$this->coreRequestBuilder->uninstallSocialTables();
		$this->coreRequestBuilder->uninstallFromMigrations();
		$this->coreRequestBuilder->uninstallFromJobs();
		$this->configService->unsetAppConfig();
	}
}
