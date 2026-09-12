<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\DomainPurgeService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Purging a domain by hand.
 *
 * Blocking one queues the purge already; this is for the cases that are not a
 * fresh block — a domain blocked before this existed, a job that failed half
 * way, or an admin who wants the data gone without waiting for cron.
 */
class DomainPurge extends SocialCommand {
	public function __construct(
		private DomainPurgeService $domainPurgeService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:domain:purge')
			->setDescription(
				'Delete everything an instance already sent this one: its cached accounts, '
				. 'their posts, the follows in both directions and the notifications they caused. '
				. 'Blocking the domain first is what stops it coming back'
			)
			->addArgument('domain', InputArgument::REQUIRED, 'the instance to purge, e.g. spam.example')
			->addOption(
				'batches', 'b', InputOption::VALUE_REQUIRED,
				'stop after this many batches of ' . DomainPurgeService::BATCH
				. ' accounts instead of running to the end'
			)
			->addOption('check', '', InputOption::VALUE_NONE, 'only report whether anything is left');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$domain = (string)$input->getArgument('domain');

		try {
			if ((bool)$input->getOption('check')) {
				$output->writeln(
					$this->domainPurgeService->hasRemains($domain)
						? $domain . ' still has data on this instance'
						: 'nothing of ' . $domain . ' is left on this instance'
				);

				return 0;
			}

			$batches = $input->getOption('batches');
			$purged = $this->domainPurgeService->purge($domain, ($batches === null) ? 0 : (int)$batches);
		} catch (InvalidResourceException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return 1;
		}

		$output->writeln(sprintf('%d accounts of %s purged', $purged, $domain));
		if ($this->domainPurgeService->hasRemains($domain)) {
			// only reachable with --batches: without it the purge runs until
			// there is nothing left to find
			$output->writeln('<comment>more is left; run the command again to continue</comment>');
		}

		// unblocking does not undo this, and the message is the one place an
		// admin sees that before the rows are gone rather than after
		$output->writeln('<info>this cannot be undone: unblocking the domain does not restore what was deleted</info>');

		return 0;
	}
}
