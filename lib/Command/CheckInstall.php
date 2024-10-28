<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OC\Core\Command\Base;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamTagsRequest;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PushService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IUserManager;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CheckInstall extends Base {
	use TArrayTools;

	private IUserManager $userManager;
	private StreamRequest $streamRequest;
	private CacheActorService $cacheActorService;
	private StreamDestRequest $streamDestRequest;
	private StreamTagsRequest $streamTagsRequest;
	private CheckService $checkService;
	private ConfigService $configService;
	private PushService $pushService;
	private MiscService $miscService;

	public function __construct(
		IUserManager $userManager, StreamRequest $streamRequest, StreamDestRequest $streamDestRequest,
		StreamTagsRequest $streamTagsRequest, CacheActorService $cacheActorService,
		CheckService $checkService, ConfigService $configService, PushService $pushService,
		MiscService $miscService,
	) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->streamRequest = $streamRequest;
		$this->streamDestRequest = $streamDestRequest;
		$this->streamTagsRequest = $streamTagsRequest;
		$this->cacheActorService = $cacheActorService;
		$this->checkService = $checkService;
		$this->configService = $configService;
		$this->miscService = $miscService;
		$this->pushService = $pushService;
	}

	protected function configure() {
		parent::configure();
		$this->setName('social:check:install')
			->addOption('index', '', InputOption::VALUE_NONE, 'regenerate your index')
//			 ->addOption(
//				 'push', '', InputOption::VALUE_REQUIRED,
//				 'a local account used to test integration to Nextcloud Push',
//				 ''
//			 )
			->setDescription('Check the integrity of the installation');
	}


	/**
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->askRegenerateIndex($input, $output)) {
			return 0;
		}

		//		if ($this->checkPushApp($input, $output)) {
		//			return;
		//		}

		$result = $this->checkService->checkInstallationStatus();

		$output->writeln('- ' . $this->getInt('invalidFollowers', $result, 0) . ' invalid followers removed');
		$output->writeln('- ' . $this->getInt('invalidNotes', $result, 0) . ' invalid notes removed');

		$output->writeln('');
		$output->writeln('- Your current configuration: ');
		$output->writeln(json_encode($this->configService->getConfig(), JSON_PRETTY_PRINT));

		return 0;
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

		// push was not implemented on 18
		//		$wrapper = $this->pushService->testOnAccount($userId);

		//		$output->writeln(json_encode($wrapper, JSON_PRETTY_PRINT));

		return true;
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return bool
	 */
	private function askRegenerateIndex(InputInterface $input, OutputInterface $output): bool {
		if (!$input->getOption('index')) {
			return false;
		}

		$helper = $this->getHelper('question');
		$output->writeln('<error>This command will regenerate the index of the Social App.</error>');
		$output->writeln(
			'<error>This operation can takes a while, and the Social App might not be stable during the process.</error>'
		);
		$output->writeln('');
		$question = new ConfirmationQuestion(
			'<info>Do you confirm this operation?</info> (y/N) ', false, '/^(y|Y)/i'
		);

		if (!$helper->ask($input, $output, $question)) {
			return true;
		}

		$this->streamDestRequest->emptyStreamDest();
		$this->streamTagsRequest->emptyStreamTags();
		$this->regenerateIndex($output);

		return true;
	}

	private function regenerateIndex(OutputInterface $output): void {
		$streams = $this->streamRequest->getAll();
		$progressBar = new ProgressBar($output, count($streams));
		$progressBar->start();

		foreach ($streams as $stream) {
			try {
				$this->streamDestRequest->generateStreamDest($stream);
				$this->streamTagsRequest->generateStreamTags($stream);
			} catch (Exception $e) {
				echo '-- ' . get_class($e) . ' - ' . $e->getMessage() . ' - ' . json_encode($stream) . "\n";
			}
			$progressBar->advance();
		}

		$progressBar->finish();
		$output->writeln('');
	}
}
