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
use OCA\Social\Service\LikeService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\StreamService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class NoteLike
 *
 * @package OCA\Social\Command
 */
class NoteLike extends Base {
	private StreamService $streamService;

	private AccountService $accountService;

	private LikeService $likeService;

	private MiscService $miscService;


	/**
	 * NoteBoost constructor.
	 *
	 * @param AccountService $accountService
	 * @param StreamService $streamService
	 * @param LikeService $likeService
	 * @param MiscService $miscService
	 */
	public function __construct(
		AccountService $accountService, StreamService $streamService, LikeService $likeService,
		MiscService $miscService
	) {
		parent::__construct();

		$this->streamService = $streamService;
		$this->likeService = $likeService;
		$this->accountService = $accountService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:note:like')
			 ->addArgument('user_id', InputArgument::REQUIRED, 'userId of the author')
			 ->addArgument('note_id', InputArgument::REQUIRED, 'Note to like')
			 ->addOption('unlike', '', InputOption::VALUE_NONE, 'Unlike')
			 ->setDescription('Like a note');
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
		if (!$input->getOption('unlike')) {
			$activity = $this->likeService->create($actor, $noteId, $token);
		} else {
			$activity = $this->likeService->delete($actor, $noteId, $token);
		}

		echo 'object: ' . json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		echo 'token: ' . $token . "\n";

		return 0;
	}
}
