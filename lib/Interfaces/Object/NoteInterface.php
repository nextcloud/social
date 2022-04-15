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
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\PushService;

class NoteInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	private StreamRequest $streamRequest;
	private PushService $pushService;

	public function __construct(StreamRequest $streamRequest, PushService $pushService) {
		$this->streamRequest = $streamRequest;
		$this->pushService = $pushService;
	}

	/**
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
	 * @throws InvalidOriginException|ItemAlreadyExistsException
	 */
	public function activity(Acore $activity, ACore $item): void {
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

	public function save(ACore $item): void {
		/** @var Note $note */
		$note = $item;
		try {
			$this->streamRequest->getStreamById($note->getId());
		} catch (StreamNotFoundException $e) {
			$this->streamRequest->save($note);
			$this->pushService->onNewStream($note->getId());
		}
	}

	public function delete(ACore $item): void {
		/** @var Note $item */
		$this->streamRequest->deleteById($item->getId(), Note::TYPE);
	}
}
