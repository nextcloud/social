<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use OCA\Social\Atproto\Identity\KeySealService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ social:atproto:keys:reseal`: the Bluesky keys sealed again under
 * the instance secret in use, after `config.php`'s `secret` changed. The
 * previous secret comes from the OC_OLD_SECRET environment variable,
 * never from the command line. Without the option it only counts the
 * keys that do not open.
 */
class AtprotoKeysReseal extends SocialCommand {
	public function __construct(
		private KeySealService $keySeals,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:atproto:keys:reseal')
			->setDescription('Seal every Bluesky key again under the current instance secret, opening them with the previous one; without --old-secret-from-env, count the keys that do not open')
			->addOption('old-secret-from-env', '', InputOption::VALUE_NONE, 'read the previous instance secret from the OC_OLD_SECRET environment variable');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$input->getOption('old-secret-from-env')) {
			$sealed = $this->keySeals->countSealed();
			$output->writeln('- ' . $sealed . ' keys cannot be opened with the current secret' . ($sealed > 0 ? '; run again with --old-secret-from-env and the previous secret in OC_OLD_SECRET' : ''));

			return $sealed === 0 ? 0 : 1;
		}
		$oldSecret = (string)getenv('OC_OLD_SECRET');
		if ($oldSecret === '') {
			$output->writeln('<error>--old-secret-from-env needs the previous secret in OC_OLD_SECRET</error>');

			return 1;
		}
		$result = $this->keySeals->reseal($oldSecret);
		$output->writeln(sprintf('- %d keys sealed again, %d could not be opened with either secret', $result['resealed'], $result['failed']));

		return $result['failed'] === 0 ? 0 : 1;
	}
}
