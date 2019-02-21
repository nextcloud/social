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
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PostService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class NoteCreate extends Base {

	/** @var ConfigService */
	private $configService;

	/** @var ActivityService */
	private $activityService;

	/** @var AccountService */
	private $accountService;

	/** @var PostService */
	private $postService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/**
	 * NoteCreate constructor.
	 *
	 * @param ActivityService $activityService
	 * @param AccountService $accountService
	 * @param PostService $postService
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActivityService $activityService, AccountService $accountService,
		PostService $postService, CurlService $curlService,
		ConfigService $configService, MiscService $miscService
	) {
		parent::__construct();

		$this->activityService = $activityService;
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
		$this->setName('social:note:create')
			 ->addOption(
				 'replyTo', 'r', InputOption::VALUE_OPTIONAL, 'in reply to an existing thread'
			 )
			 ->addOption(
				 'to', 't', InputOption::VALUE_OPTIONAL, 'mentioning people'
			 )
			 ->addOption(
				 'type', 'y', InputOption::VALUE_OPTIONAL,
				 'type: public (default), followers, unlisted, direct'
			 )
			 ->addOption(
				 'hashtag', 'g', InputOption::VALUE_OPTIONAL,
				 'hashtag, without the leading #'
			 )
			 ->addArgument('userid', InputArgument::REQUIRED, 'userId of the author')
			 ->addArgument('content', InputArgument::REQUIRED, 'content of the post')
			 ->setDescription('Create a new note');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {

		$userId = $input->getArgument('userid');
		$content = $input->getArgument('content');
		$to = $input->getOption('to');
		$hashtag = $input->getOption('hashtag');
		$replyTo = $input->getOption('replyTo');
		$type = $input->getOption('type');

		$actor = $this->accountService->getActorFromUserId($userId);
		$post = new Post($actor);
		$post->setContent($content);
		$post->setType(($type === null) ? '' : $type);
		$post->setReplyTo(($replyTo === null) ? '' : $replyTo);
		$post->addTo(($to === null) ? '' : $to);
		$post->setHashtags(($hashtag === null) ? [] : [$hashtag]);

		$token = $this->postService->createPost($post, $activity);

		echo 'object: ' . json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		echo 'token: ' . $token . "\n";
	}

}

