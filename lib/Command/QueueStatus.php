<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FederationHealthService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueStatus extends SocialCommand {
	public function __construct(
		private RequestQueueService $requestQueueService,
		private ConfigService $configService,
		private MiscService $miscService,
		private FederationHealthService $federationHealthService,
	) {
		parent::__construct();
	}

	/**
	 *
	 */
	#[\Override]
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
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$token = $input->getOption('token');

		if ($token === null) {
			// an administrator asking after "the queue" wants the state of the
			// queue, not an argument about which request they meant
			$this->reportHealth($output);

			return 0;
		}

		$requests = $this->requestQueueService->getRequestFromToken($token);

		foreach ($requests as $request) {
			$output->writeLn(json_encode($request));
		}

		return 0;
	}

	private function reportHealth(OutputInterface $output): void {
		$summary = $this->federationHealthService->summary();

		$output->writeln($summary['waiting'] . ' deliveries waiting, ' . $summary['running'] . ' being sent');

		if ($summary['failing'] === 0) {
			$output->writeln('<info>nothing is failing to deliver</info>');

			return;
		}

		$output->writeln(
			'<comment>' . $summary['failing'] . ($summary['truncated'] ? '+' : '')
			. ' have failed at least once, ' . $summary['atRisk']
			. ' are close to being given up on (abandoned after '
			. $summary['maxTries'] . ' attempts)</comment>'
		);
		$output->writeln('');

		foreach ($summary['instances'] as $instance) {
			$output->writeln(
				sprintf(
					'  %-40s %4d waiting   %2d/%d attempts   last %s',
					$instance['host'],
					$instance['requests'],
					$instance['tries'],
					$summary['maxTries'],
					$instance['last'] > 0 ? gmdate('Y-m-d H:i', $instance['last']) : 'never'
				)
			);
		}
	}
}
