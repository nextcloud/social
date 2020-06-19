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
//use OC\Push\Model\Helper\PushCallback;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\QueryException;
//use OCP\Push\Exceptions\PushInstallException;
//use OCP\Push\IPushManager;
//use OCP\Push\Model\IPushWrapper;


/**
 * Class PushService
 *
 * @package OCA\Social\Service
 */
class PushService {


	use TAsync;

//
//	/** @var IPushManager */
//	private $pushManager;

	/** @var DetailsService */
	private $detailsService;

	/** @var StreamService */
	private $streamService;

	/** @var MiscService */
	private $miscService;


	/**
	 * PushService constructor.
	 *
	 * @param DetailsService $detailsService
	 * @param StreamService $streamService
	 * @param MiscService $miscService
	 */
	public function __construct(
		DetailsService $detailsService, StreamService $streamService, MiscService $miscService
	) {
		$this->detailsService = $detailsService;
		$this->streamService = $streamService;
		$this->miscService = $miscService;

		// FIX ME: nc18/push
//		if ($this->miscService->getNcVersion() >= 19) {
//			try {
//				$this->pushManager = OC::$server->query(IPushManager::class);
//			} catch (QueryException $e) {
//				$miscService->log('QueryException while loading IPushManager - ' . $e->getMessage());
//			}
//		}
	}


	/**
	 * @param string $streamId
	 */
	public function onNewStream(string $streamId) {
		return;
//		if ($this->miscService->getNcVersion() < 19) {
//			return;
//		}
//
//		if (!$this->pushManager->isAvailable()) {
//			return;
//		}
//
//		try {
//			$stream = $this->streamService->getStreamById($streamId);
//		} catch (StreamNotFoundException $e) {
//			return;
//		}
//
//		try {
//			$pushHelper = $this->pushManager->getPushHelper();
//			$details = $this->detailsService->generateDetailsFromStream($stream);
//		} catch (PushInstallException $e) {
//			return;
//		} catch (SocialAppConfigException $e) {
//			return;
//		}
//
//		$home = array_map(
//			function(Person $item): string {
//				return $item->getUserId();
//			}, $details->getHomeViewers()
//		);
//
//		$callback = new PushCallback('social', 'timeline.home');
//		$callback->setPayloadSerializable($stream);
//		$callback->addUsers($home);
//		$pushHelper->toCallback($callback);
//
//		$direct = array_map(
//			function(Person $item): string {
//				return $item->getUserId();
//			}, $details->getDirectViewers()
//		);
//
//		$callback = new PushCallback('social', 'timeline.direct');
//		$callback->addUsers($direct);
//		$callback->setPayloadSerializable($stream);
//		$pushHelper->toCallback($callback);
	}

//
//	/**
//	 * @param $userId
//	 *
//	 * @return IPushWrapper
//	 * @throws PushInstallException
//	 */
//	public function testOnAccount(string $userId): IPushWrapper {
////		$pushHelper = $this->pushManager->getPushHelper();
////
////		return $pushHelper->test($userId);
//	}

}

