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


use Exception;
use OCA\Social\Db\NotesRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\NoteNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\StreamQueueService;


/**
 * Class AnnounceInterface
 *
 * @package OCA\Social\Interfaces\Object
 */
class AnnounceInterface implements IActivityPubInterface {


	/** @var NotesRequest */
	private $notesRequest;

	/** @var StreamQueueService */
	private $streamQueueService;

	/** @var MiscService */
	private $miscService;


	/**
	 * AnnounceInterface constructor.
	 *
	 * @param NotesRequest $notesRequest
	 * @param StreamQueueService $streamQueueService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NotesRequest $notesRequest, StreamQueueService $streamQueueService, MiscService $miscService
	) {
		$this->notesRequest = $notesRequest;
		$this->streamQueueService = $streamQueueService;
		$this->miscService = $miscService;
	}


	/**
	 * @param ACore $activity
	 * @param ACore $item
	 *
	 * @throws InvalidOriginException
	 */
	public function activity(Acore $activity, ACore $item) {
		$item->checkOrigin($activity->getId());

		if ($activity->getType() === Undo::TYPE) {
			$item->checkOrigin($item->getId());
			$this->delete($item);
		}
	}


	/**
	 * @param ACore $item
	 *
	 * @throws InvalidOriginException
	 * @throws Exception
	 */
	public function processIncomingRequest(ACore $item) {
		/** @var Stream $item */
		$item->checkOrigin($item->getId());

		$this->save($item);
	}


	/**
	 * @param ACore $item
	 */
	public function processResult(ACore $item) {
	}


	/**
	 * @param string $id
	 *
	 * @return ACore
	 * @throws ItemNotFoundException
	 */
	public function getItemById(string $id): ACore {
		throw new ItemNotFoundException();
	}


	/**
	 * @param ACore $item
	 *
	 * @throws Exception
	 */
	public function save(ACore $item) {
		/** @var Announce $item */
		try {
			$this->notesRequest->getNoteById($item->getId());
		} catch (NoteNotFoundException $e) {
			$objectId = $item->getObjectId();
			$item->addCacheItem($objectId);
			$this->notesRequest->save($item);

			$this->streamQueueService->generateStreamQueue(
				$item->getRequestToken(), StreamQueue::TYPE_CACHE, $item->getId()
			);
		}
	}


	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
		try {
			$stream = $this->notesRequest->getNoteById($item->getId());
			if ($stream->getType() === Announce::TYPE) {
				$this->notesRequest->deleteNoteById($item->getId());
			}
		} catch (NoteNotFoundException $e) {
		}
	}

}

