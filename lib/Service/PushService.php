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


namespace OCA\Social\Service;


use daita\MySmallPhpTools\Traits\TAsync;
use OC;
use OC\Stratos\Model\Helper\StratosCallback;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\QueryException;
use OCP\Stratos\Exceptions\StratosInstallException;
use OCP\Stratos\IStratosManager;
use OCP\Stratos\Model\IStratosWrapper;


/**
 * Class PushService
 *
 * @package OCA\Social\Service
 */
class PushService {


	use TAsync;


	/** @var IStratosManager */
	private $stratosManager;

	/** @var DetailsService */
	private $detailsService;

	/** @var StreamService */
	private $streamService;

	/** @var MiscService */
	private $miscService;


	/**
	 * DetailsService constructor.
	 *
	 * @param DetailsService $detailsService
	 * @param MiscService $miscService
	 */
	public function __construct(
		DetailsService $detailsService, StreamService $streamService, MiscService $miscService
	) {
		$this->detailsService = $detailsService;
		$this->streamService = $streamService;
		$this->miscService = $miscService;

		// FIX ME: nc18/stratos
		if ($this->miscService->getNcVersion() >= 17) {
			try {
				$this->stratosManager = OC::$server->query(IStratosManager::class);
			} catch (QueryException $e) {
				$miscService->log('QueryException while loading StratosManager');
			}
		}
	}


	/**
	 * @param string $streamId
	 *
	 * @throws SocialAppConfigException
	 * @throws StratosInstallException
	 */
	public function onNewStream(string $streamId) {
		// FIXME: remove in nc18
		if ($this->miscService->getNcVersion() < 17) {
			return;
		}

		if (!$this->stratosManager->isAvailable()) {
			return;
		}

		try {
			$stream = $this->streamService->getStreamById($streamId);
		} catch (StreamNotFoundException $e) {
			return;
		}

		$stratosHelper = $this->stratosManager->getStratosHelper();
		$details = $this->detailsService->generateDetailsFromStream($stream);
		$home = array_map(
			function(Person $item): string {
				return $item->getUserId();
			}, $details->getHomeViewers()
		);

		$callback = new StratosCallback('social', 'timeline.home');
		$callback->setPayloadSerializable($stream);
		$callback->addUsers($home);
		$stratosHelper->toCallback($callback);

		$direct = array_map(
			function(Person $item): string {
				return $item->getUserId();
			}, $details->getDirectViewers()
		);

		$callback = new StratosCallback('social', 'timeline.direct');
		$callback->addUsers($direct);
		$callback->setPayloadSerializable($stream);
		$stratosHelper->toCallback($callback);
	}


	/**
	 * @param $userId
	 *
	 * @return IStratosWrapper
	 * @throws StratosInstallException
	 */
	public function testOnAccount(string $userId): IStratosWrapper {
		$stratosHelper = $this->stratosManager->getStratosHelper();

		return $stratosHelper->test($userId);
	}

}
