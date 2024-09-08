<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OC;
use OCA\Social\Exceptions\SocialAppConfigException;
//use OC\Push\Model\Helper\PushCallback;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\Traits\TAsync;
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

	private DetailsService $detailsService;
	private StreamService $streamService;
	private MiscService $miscService;


	/**
	 * PushService constructor.
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
