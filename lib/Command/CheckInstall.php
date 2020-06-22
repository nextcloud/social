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


use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OC\Core\Command\Base;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamTagsRequest;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PushService;
use OCP\IUserManager;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;


class CheckInstall extends Base {


	use TArrayTools;


	/** @var IUserManager */
	private $userManager;

	/** @var StreamRequest */
	private $streamRequest;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var StreamDestRequest */
	private $streamDestRequest;

	/** @var StreamTagsRequest */
	private $streamTagsRequest;

	/** @var CheckService */
	private $checkService;

	/** @var */
	private $configService;

	/** @var PushService */
	private $pushService;

	/** @var MiscService */
	private $miscService;


	/**
	 * CacheUpdate constructor.
	 *
	 * @param IUserManager $userManager
	 * @param StreamRequest $streamRequest
	 * @param StreamDestRequest $streamDestRequest
	 * @param StreamTagsRequest $streamTagsRequest
	 * @param CacheActorService $cacheActorService
	 * @param CheckService $checkService
	 * @param ConfigService $configService
	 * @param PushService $pushService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IUserManager $userManager, StreamRequest $streamRequest, StreamDestRequest $streamDestRequest,
		StreamTagsRequest $streamTagsRequest, CacheActorService $cacheActorService,
		CheckService $checkService, ConfigService $configService, PushService $pushService,
		MiscService $miscService
	) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->streamRequest = $streamRequest;
		$this->streamDestRequest = $streamDestRequest;
		$this->streamTagsRequest = $streamTagsRequest;
		$this->cacheActorService = $cacheActorService;
		$this->checkService = $checkService;
		$this->configService = $configService;
		$this->miscService = $miscService;
		$this->pushService = $pushService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:check:install')
			 ->addOption('index', '', InputOption::VALUE_NONE, 'regenerate your index')
//			 ->addOption(
//				 'push', '', InputOption::VALUE_REQUIRED,
//				 'a local account used to test integration to Nextcloud Push',
//				 ''
//			 )
			 ->setDescription('Check the integrity of the installation');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		if ($this->askRegenerateIndex($input, $output)) {
			return;
		}

//		if ($this->checkPushApp($input, $output)) {
//			return;
//		}

		$result = $this->checkService->checkInstallationStatus();

		$output->writeln('- ' . $this->getInt('invalidFollowers', $result, 0) . ' invalid followers removed');
		$output->writeln('- ' . $this->getInt('invalidNotes', $result, 0) . ' invalid notes removed');

		$output->writeln('');
		$output->writeln('- Your current configuration: ');
		$output->writeln(json_encode($this->configService->getConfig(), JSON_PRETTY_PRINT));
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return bool
	 * @throws Exception
	 */
	private function checkPushApp(InputInterface $input, OutputInterface $output): bool {
		$userId = $input->getOption('push');
		if ($userId === '') {
			return false;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new Exception('unknown user');
		}

		// push was not implemented on 18
//		$wrapper = $this->pushService->testOnAccount($userId);

//		$output->writeln(json_encode($wrapper, JSON_PRETTY_PRINT));

		return true;
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return bool
	 */
	private function askRegenerateIndex(InputInterface $input, OutputInterface $output): bool {
		if (!$input->getOption('index')) {
			return false;
		}

		$helper = $this->getHelper('question');
		$output->writeln('<error>This command will regenerate the index of the Social App.</error>');
		$output->writeln(
			'<error>This operation can takes a while, and the Social App might not be stable during the process.</error>'
		);
		$output->writeln('');
		$question = new ConfirmationQuestion(
			'<info>Do you confirm this operation?</info> (y/N) ', false, '/^(y|Y)/i'
		);

		if (!$helper->ask($input, $output, $question)) {
			return true;
		}

		$this->streamDestRequest->emptyStreamDest();
		$this->streamTagsRequest->emptyStreamTags();
		$this->regenerateIndex($output);

		return true;
	}


	/**
	 * @param OutputInterface $output
	 */
	private function regenerateIndex(OutputInterface $output) {
		$streams = $this->streamRequest->getAll();
		$progressBar = new ProgressBar($output, count($streams));
		$progressBar->start();

		foreach ($streams as $stream) {
			try {
				$this->streamDestRequest->generateStreamDest($stream);
				$this->streamTagsRequest->generateStreamTags($stream);
			} catch (Exception $e) {
				echo '-- ' . get_class($e) . ' - ' . $e->getMessage() . ' - ' . json_encode($stream) . "\n";
			}
			$progressBar->advance();
		}

		$progressBar->finish();
		$output->writeln('');
	}

}

