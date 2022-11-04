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
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\UnknownTimelineException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\TimelineOptions;
use OCA\Social\Service\AccountService;
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
		ConfigService $configService
	) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->streamRequest = $streamRequest;
		$this->accountService = $accountService;
		$this->configService = $configService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:stream')
			 ->addArgument('userId', InputArgument::REQUIRED, 'viewer')
			 ->addArgument('timeline', InputArgument::REQUIRED, 'timeline')
			 ->addOption('count', '', InputOption::VALUE_REQUIRED, 'number of elements', '5')
			 ->addOption('min_id', '', InputOption::VALUE_REQUIRED, 'min_id', 0)
			 ->addOption('max_id', '', InputOption::VALUE_REQUIRED, 'max_id', 0)
			 ->addOption('crop', '', InputOption::VALUE_REQUIRED, 'crop', 0)
			 ->addOption('json', '', InputOption::VALUE_NONE, 'return JSON format')
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

		$this->asJson = $input->getOption('json');
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

		$options = new TimelineOptions();
		$options->setFormat(Stream::FORMAT_LOCAL);
		$options->setLimit(intval($input->getOption('count')))
				->setMinId(intval($input->getOption('min_id')))
				->setMaxId(intval($input->getOption('max_id')));

		try {
			$options->setTimeline($input->getArgument('timeline'));
			$this->outputStreams($this->streamRequest->getTimeline($options));
		} catch (UnknownTimelineException $e) {
			echo $input->getArgument('timeline');
			$this->displayUnsupportedStream($options);
		}

		return 0;
	}


	/**
	 * @param Person $actor
	 * @param string $timeline
	 *
	 * @throws Exception
	 */
	private function displayUnsupportedStream(TimelineOptions $options) {
		switch ($options->getTimeline()) {
			case 'direct':
				$stream = $this->streamRequest->getTimelineDirect(0, $options->getLimit());
				$this->outputStreams($stream);
				break;

			case 'notifications':
				$stream = $this->streamRequest->getTimelineNotifications(0, $options->getLimit());
				$this->outputStreams($stream);
				break;

			case 'liked':
				$stream = $this->streamRequest->getTimelineLiked(0, $options->getLimit());
				$this->outputStreams($stream);
				break;

			default:
				throw new Exception(
					'Unknown timeline. Try ' . implode(', ', TimelineOptions::$availableTimelines)
					. ', direct, notifications, liked'
				);
		}
	}
}
