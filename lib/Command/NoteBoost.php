<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OC\Core\Command\Base;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\StreamService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class NoteBoost
 *
 * @package OCA\Social\Command
 */
class NoteBoost extends Base {
	private StreamService $streamService;
	private AccountService $accountService;
	private BoostService $boostService;

	public function __construct(
		AccountService $accountService,
		StreamService $streamService,
		BoostService $boostService,
	) {
		parent::__construct();

		$this->streamService = $streamService;
		$this->boostService = $boostService;
		$this->accountService = $accountService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:note:boost')
			->addArgument('user_id', InputArgument::REQUIRED, 'userId of the author')
			->addArgument('note_id', InputArgument::REQUIRED, 'Note to boost')
			->addOption('unboost', '', InputOption::VALUE_NONE, 'Unboost')
			->setDescription('Boost a note');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user_id');
		$noteId = $input->getArgument('note_id');

		$actor = $this->accountService->getActorFromUserId($userId);
		$this->streamService->setViewer($actor);

		$token = '';
		if (!$input->getOption('unboost')) {
			$activity = $this->boostService->create($actor, $noteId, $token);
		} else {
			$activity = $this->boostService->delete($actor, $noteId, $token);
		}

		echo 'object: ' . json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		echo 'token: ' . $token . "\n";

		return 0;
	}
}
