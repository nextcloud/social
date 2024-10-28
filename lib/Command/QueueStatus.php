<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OC\Core\Command\Base;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueStatus extends Base {
	private ConfigService $configService;

	private RequestQueueService $requestQueueService;

	private MiscService $miscService;


	/**
	 * NoteCreate constructor.
	 *
	 * @param RequestQueueService $requestQueueService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		RequestQueueService $requestQueueService, ConfigService $configService, MiscService $miscService,
	) {
		parent::__construct();

		$this->requestQueueService = $requestQueueService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:queue:status')
			->addOption(
				'token', 't', InputOption::VALUE_OPTIONAL, 'token of a request'
			)
			->setDescription('Return status on the request queue');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$token = $input->getOption('token');

		if ($token === null) {
			throw new Exception('As of today, --token is mandatory');
		}

		$requests = $this->requestQueueService->getRequestFromToken($token);

		foreach ($requests as $request) {
			$output->writeLn(json_encode($request));
		}

		return 0;
	}
}
