<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Service\CollectionPackService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ social:starter-packs:refresh`: reads the starter packs the
 * `starter_pack_sources` app value names now, rather than at the next daily
 * run of `Cron\StarterPackSources`.
 */
class StarterPacksRefresh extends SocialCommand {
	public function __construct(
		private CollectionPackService $collectionPackService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:starter-packs:refresh')
			->setDescription('Read the starter packs other servers publish again: fediverse collections and Bluesky\'s suggestions');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('Sources: ' . implode(', ', $this->collectionPackService->sources()));
		$result = $this->collectionPackService->refresh();
		$output->writeln(sprintf(
			'%d starter pack(s) with %d account(s) read from %d source(s); %d account(s) left out without a valid authorization; %d source(s) not reached',
			$result['packs'], $result['members'], $result['sources'], $result['refused'], $result['failed']
		));

		return 0;
	}
}
