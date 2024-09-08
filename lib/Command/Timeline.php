<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Stream
 *
 * @package OCA\Social\Command
 */
class Timeline extends ExtendedBase {
	private IUserManager $userManager;
	private StreamRequest $streamRequest;
	private AccountService $accountService;
	private CacheActorService $cacheActorService;
	private ConfigService $configService;

	private ?int $count = null;


	/**
	 * Timeline constructor.
	 *
	 * @param IUserManager $userManager
	 * @param StreamRequest $streamRequest
	 * @param AccountService $accountService
	 * @param ConfigService $configService
	 */
	public function __construct(
		IUserManager $userManager,
		StreamRequest $streamRequest,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		ConfigService $configService
	) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->streamRequest = $streamRequest;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:timeline')
			 ->addArgument('userId', InputArgument::REQUIRED, 'viewer')
			 ->addArgument('timeline', InputArgument::REQUIRED, 'timeline')
			 ->addOption('local', '', InputOption::VALUE_NONE, 'public')
			 ->addOption('min_id', '', InputOption::VALUE_REQUIRED, 'min_id', 0)
			 ->addOption('max_id', '', InputOption::VALUE_REQUIRED, 'max_id', 0)
			 ->addOption('since', '', InputOption::VALUE_REQUIRED, 'since', 0)
			 ->addOption('limit', '', InputOption::VALUE_REQUIRED, 'limit', 5)
			 ->addOption('account', '', InputOption::VALUE_REQUIRED, 'account', '')
			 ->addOption('crop', '', InputOption::VALUE_REQUIRED, 'crop', 0)
			 ->setDescription('Get stream by timeline and viewer');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output = new ConsoleOutput();
		$this->output = $output->section();

		$this->asJson = (strtolower($input->getOption('output')) === 'json');
		$this->crop = intval($input->getOption('crop'));

		$userId = $input->getArgument('userId');
		if ($this->userManager->get($userId) === null) {
			throw new Exception('Unknown user');
		}

		$actor = $this->accountService->getActorFromUserId($userId);

		if (!$this->asJson) {
			$this->outputActor($actor);
		}

		$this->streamRequest->setViewer($actor);

		$options = new ProbeOptions();
		$options->setFormat(Stream::FORMAT_LOCAL);
		$options->setLimit(intval($input->getOption('limit')))
				->setMinId(intval($input->getOption('min_id')))
				->setMaxId(intval($input->getOption('max_id')))
				->setSince(intval($input->getOption('since')));

		if ($input->getOption('local')) {
			$options->setLocal(true);
		}

		$timeline = $input->getArgument('timeline');
		if (str_starts_with($timeline, '#')) {
			$options->setProbe(ProbeOptions::HASHTAG)
					->setArgument(substr($timeline, 1));
		} else {
			$options->setProbe($timeline);
		}

		if ($input->getOption('account') !== '') {
			$local = $this->cacheActorService->getFromLocalAccount($input->getOption('account'));
			$options->setAccountId($local->getId());
		}

		$this->outputStreams($this->streamRequest->getTimeline($options));

		return 0;
	}
}
