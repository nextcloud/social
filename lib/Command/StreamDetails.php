<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\DetailsService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\StreamService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class StreamDetails
 *
 * @package OCA\Social\Command
 */
class StreamDetails extends ExtendedBase {
	private StreamService $streamService;

	private DetailsService $detailsService;

	private MiscService $miscService;


	/**
	 * StreamDetails constructor.
	 *
	 * @param StreamService $streamService
	 * @param DetailsService $detailsService
	 * @param MiscService $miscService
	 */
	public function __construct(
		StreamService $streamService, DetailsService $detailsService, MiscService $miscService,
	) {
		parent::__construct();

		$this->streamService = $streamService;
		$this->detailsService = $detailsService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:details')
			->addArgument('streamId', InputArgument::REQUIRED, 'Id of the Stream item')
			->addOption('json', '', InputOption::VALUE_NONE, 'return JSON format')
			->setDescription('Get details about a Stream item');
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
		$streamId = $input->getArgument('streamId');

		try {
			$stream = $this->streamService->getStreamById($streamId);
		} catch (StreamNotFoundException $e) {
			throw new Exception('Unknown item');
		}

		$details = $this->detailsService->generateDetailsFromStream($stream);

		if ($this->asJson) {
			$this->output->writeln(json_encode($details, JSON_PRETTY_PRINT));

			return 0;
		}

		$this->outputStream($stream);
		$this->output->writeln('');

		$this->output->writeln('<comment>Affected Timelines</comment>:');
		$home = array_map(
			function (Person $item): string {
				return $item->getUserId();
			}, $details->getHomeViewers()
		);

		$this->output->writeln('* <info>Home</info>: ' . json_encode($home, JSON_PRETTY_PRINT));
		$direct = array_map(
			function (Person $item): string {
				return $item->getUserId();
			}, $details->getDirectViewers()
		);

		$this->output->writeln('* <info>Direct</info>: ' . json_encode($direct, JSON_PRETTY_PRINT));
		$this->output->writeln('* <info>Public</info>: ' . ($details->isPublic() ? 'true' : 'false'));
		$this->output->writeln('* <info>Federated</info>: ' . ($details->isFederated() ? 'true' : 'false'));

		return 0;
	}
}
