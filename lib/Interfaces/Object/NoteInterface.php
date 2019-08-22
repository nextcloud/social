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


namespace OCA\Social\Interfaces\Object;


use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PushService;


class NoteInterface implements IActivityPubInterface {


	/** @var StreamRequest */
	private $streamRequest;

	/** @var CurlService */
	private $curlService;

	/** @var PushService */
	private $pushService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * NoteInterface constructor.
	 *
	 * @param StreamRequest $streamRequest
	 * @param CurlService $curlService
	 * @param PushService $pushService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		StreamRequest $streamRequest, CurlService $curlService, PushService $pushService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->streamRequest = $streamRequest;
		$this->curlService = $curlService;
		$this->pushService = $pushService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param ACore $note
	 */
	public function processIncomingRequest(ACore $note) {
	}


	/**
	 * @param ACore $item
	 */
	public function processResult(ACore $item) {
	}


	/**
	 * @param ACore $item
	 *
	 * @return ACore
	 * @throws ItemNotFoundException
	 */
	public function getItem(ACore $item): ACore {
		throw new ItemNotFoundException();
	}


	/**
	 * @param string $id
	 *
	 * @return ACore
	 * @throws ItemNotFoundException
	 */
	public function getItemById(string $id): ACore {
		try {
			return $this->streamRequest->getStreamById($id);
		} catch (StreamNotFoundException $e) {
			throw new ItemNotFoundException();
		}
	}


	/**
	 * @param ACore $activity
	 * @param ACore $item
	 *
	 * @throws InvalidOriginException
	 */
	public function activity(Acore $activity, ACore $item) {
		/** @var Note $item */

		if ($activity->getType() === Create::TYPE) {
			$activity->checkOrigin($item->getId());
			$activity->checkOrigin($item->getAttributedTo());
			$item->setActivityId($activity->getId());
			$this->save($item);
		}

		if ($activity->getType() === Delete::TYPE) {
			$activity->checkOrigin($item->getId());
			$this->delete($item);
		}
	}


	/**
	 * @param ACore $note
	 */
	public function save(ACore $note) {
		/** @var Note $note */

		try {
			$this->streamRequest->getStreamById($note->getId());
		} catch (StreamNotFoundException $e) {
			$this->streamRequest->save($note);
			$this->pushService->onNewStream($note->getId());
		}
	}


	/**
	 * @param ACore $item
	 */
	public function update(ACore $item) {
	}


	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
		/** @var Note $item */
		$this->streamRequest->deleteById($item->getId(), Note::TYPE);
	}


	/**
	 * @param ACore $item
	 * @param string $source
	 */
	public function event(ACore $item, string $source) {
	}


}

