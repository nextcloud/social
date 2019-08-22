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


	/** @var StreamService */
	private $streamService;

	/** @var AccountService */
	private $accountService;

	/** @var LikeService */
	private $likeService;

	/** @var MiscService */
	private $miscService;


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
	protected function execute(InputInterface $input, OutputInterface $output) {
		$userId = $input->getArgument('user_id');
		$noteId = $input->getArgument('note_id');

		$actor = $this->accountService->getActorFromUserId($userId);
		$this->streamService->setViewer($actor);

		if (!$input->getOption('unlike')) {
			$activity = $this->likeService->create($actor, $noteId, $token);
		} else {
			$activity = $this->likeService->delete($actor, $noteId, $token);
		}

		echo 'object: ' . json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		echo 'token: ' . $token . "\n";
	}

}

