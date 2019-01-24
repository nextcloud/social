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
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\RequestQueueService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class QueueStatus extends Base {


	/** @var ConfigService */
	private $configService;

	/** @var RequestQueueService */
	private $requestQueueService;

	/** @var MiscService */
	private $miscService;


	/**
	 * NoteCreate constructor.
	 *
	 * @param RequestQueueService $requestQueueService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		RequestQueueService $requestQueueService, ConfigService $configService, MiscService $miscService
	) {
		parent::__construct();

		$this->requestQueueService = $requestQueueService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
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
	protected function execute(InputInterface $input, OutputInterface $output) {

		$token = $input->getOption('token');

		if ($token === null) {
			throw new Exception('As of today, --token is mandatory');
		}

		$requests = $this->requestQueueService->getRequestFromToken($token);

		foreach ($requests as $request) {
			$output->writeLn(json_encode($request));
		}

	}

}

