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


	/** @var ActivityService */
	private $activityService;

	/** @var StreamQueueService */
	private $streamQueueService;

	/** @var RequestQueueService */
	private $requestQueueService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


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
	protected function execute(InputInterface $input, OutputInterface $output) {

		$output->writeLn('processing requests queue');
		$this->processRequestQueue($output);

		$output->writeLn('processing stream queue');
		$this->processStreamQueue($output);
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
