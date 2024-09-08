<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OC\Core\Command\Base;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\StreamQueueService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueueProcess extends Base {
	private ActivityService $activityService;
	private StreamQueueService $streamQueueService;
	private RequestQueueService $requestQueueService;
	private ConfigService $configService;
	private MiscService $miscService;


	/**
	 * NoteCreate constructor.
	 *
	 * @param ActivityService $activityService
	 * @param RequestQueueService $requestQueueService
	 * @param StreamQueueService $streamQueueService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActivityService $activityService, RequestQueueService $requestQueueService,
		StreamQueueService $streamQueueService, ConfigService $configService,
		MiscService $miscService
	) {
		parent::__construct();

		$this->activityService = $activityService;
		$this->requestQueueService = $requestQueueService;
		$this->streamQueueService = $streamQueueService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:queue:process')
			 ->setDescription('Process the request queue');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeLn('processing requests queue');
		$this->processRequestQueue($output);

		$output->writeLn('processing stream queue');
		$this->processStreamQueue($output);

		return 0;
	}


	/**
	 * @param OutputInterface $output
	 */
	private function processRequestQueue(OutputInterface $output) {
		$total = 0;
		$requests = $this->requestQueueService->getRequestStandby($total);

		$output->writeLn('- found a total of ' . $total . ' requests in the queue');
		if ($total === 0) {
			return;
		}

		$output->writeLn('- ' . sizeof($requests) . ' are processable at this time');
		if (sizeof($requests) === 0) {
			return;
		}

		$this->activityService->manageInit();
		foreach ($requests as $request) {
			$request->setTimeout(ActivityService::TIMEOUT_SERVICE);
			$output->write('.');
			try {
				$this->activityService->manageRequest($request);
			} catch (SocialAppConfigException $e) {
			}
		}

		$output->writeLn('done');
	}


	private function processStreamQueue(OutputInterface $output) {
		$total = 0;
		$items = $this->streamQueueService->getRequestStandby($total);

		$output->writeLn('- found a total of ' . $total . ' not cached object in the queue');
		if ($total === 0) {
			return;
		}

		$output->writeLn('- ' . sizeof($items) . ' are processable at this time');
		if (sizeof($items) === 0) {
			return;
		}

		foreach ($items as $item) {
			$output->write('.');
			$this->streamQueueService->manageStreamQueue($item);
		}

		$output->writeLn('done');
	}
}
