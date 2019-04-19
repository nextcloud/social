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
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\NoteService;
use OCA\Social\Service\PostService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Class NoteBoost
 *
 * @package OCA\Social\Command
 */
class NoteBoost extends Base {


	/** @var ConfigService */
	private $configService;

	/** @var ActivityService */
	private $activityService;

	/** @var NoteService */
	private $noteService;

	/** @var AccountService */
	private $accountService;

	/** @var BoostService */
	private $boostService;

	/** @var PostService */
	private $postService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/**
	 * NoteBoost constructor.
	 *
	 * @param ActivityService $activityService
	 * @param AccountService $accountService
	 * @param NoteService $noteService
	 * @param BoostService $boostService
	 * @param PostService $postService
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActivityService $activityService, AccountService $accountService,
		NoteService $noteService, BoostService $boostService, PostService $postService,
		CurlService $curlService, ConfigService $configService, MiscService $miscService
	) {
		parent::__construct();

		$this->activityService = $activityService;
		$this->noteService = $noteService;
		$this->boostService = $boostService;
		$this->accountService = $accountService;
		$this->postService = $postService;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:note:boost')
			 ->addArgument('userid', InputArgument::REQUIRED, 'userId of the author')
			 ->addArgument('note', InputArgument::REQUIRED, 'Note to boost')
			 ->addOption('unboost', '', InputOption::VALUE_NONE, 'Unboost')
			 ->setDescription('Boost a note');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$userId = $input->getArgument('userid');
		$noteId = $input->getArgument('note');

		$actor = $this->accountService->getActorFromUserId($userId);
		$this->noteService->setViewer($actor);
		$token = '';
		if (!$input->getOption('unboost')) {
			$activity = $this->boostService->create($actor, $noteId, $token);
		} else {
			$activity = $this->boostService->delete($actor, $noteId, $token);
		}

		echo 'object: ' . json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		echo 'token: ' . $token . "\n";
	}

}

