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


use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Tombstone;
use OCA\Social\Model\ActivityPub\Follow;
use OCA\Social\Model\ActivityPub\Note;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Service\ActivityPub\DeleteService;
use OCA\Social\Service\ActivityPub\FollowService;
use OCA\Social\Service\ActivityPub\NoteService;
use OCA\Social\Service\ActivityPub\UndoService;


class ImportService {


	use TArrayTools;


	/** @var NoteService */
	private $noteService;

	/** @var UndoService */
	private $undoService;

	/** @var FollowService */
	private $followService;

	/** @var DeleteService */
	private $deleteService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ImportService constructor.
	 *
	 * @param NoteService $noteService
	 * @param UndoService $undoService
	 * @param FollowService $followService
	 * @param DeleteService $deleteService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NoteService $noteService, UndoService $undoService, FollowService $followService,
		DeleteService $deleteService,
		MiscService $miscService
	) {
		$this->noteService = $noteService;
		$this->undoService = $undoService;
		$this->followService = $followService;
		$this->deleteService = $deleteService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $json
	 *
	 * @return ACore
	 * @throws UnknownItemException
	 */
	public function import(string $json) {
		$data = json_decode($json, true);
		$activity = $this->createItem($data, null);

		return $activity;
	}


	/**
	 * @param array $data
	 * @param ACore $root
	 *
	 * @return ACore
	 * @throws UnknownItemException
	 */
	private function createItem(array $data, $root = null): ACore {

		switch ($this->get('type', $data)) {
			case Create::TYPE:
				$item = new Create($root);
				break;

			case Delete::TYPE:
				$item = new Delete($root);
				break;

			case Tombstone::TYPE:
				$item = new Tombstone($root);
				break;

			case Note::TYPE:
				$item = new Note($root);
				break;

			case Follow::TYPE:
				$item = new Follow($root);
				break;

			case Undo::TYPE:
				$item = new Undo($root);
				break;

			case Accept::TYPE:
				$item = new Accept($root);
				break;

			case Reject::TYPE:
				$item = new Reject($root);
				break;

			default:
				throw new UnknownItemException();
		}

		$item->import($data);
		$item->setSource(json_encode($data, JSON_UNESCAPED_SLASHES));

		try {
			$object = $this->createItem($this->getArray('object', $data, []), $item);
			$item->setObject($object);
		} catch (UnknownItemException $e) {
		}

		return $item;
	}


	/**
	 * @param ACore $activity
	 *
	 * @throws UnknownItemException
	 */
	public function parse(Acore $activity) {

		if ($activity->gotObject()) {
			try {
				$this->parse($activity->getObject());
			} catch (UnknownItemException $e) {
			}
		}

		switch ($activity->getType()) {
//			case 'Activity':
//				$service = $this;
//				break;

			case Delete::TYPE:
				$service = $this->deleteService;
				break;

//			case Undo::TYPE:
//				$service = $this->undoService;
//				break;
//
//			case Accept::TYPE:
//				$service = $this->acceptService;
//				break;
//
//			case Reject::TYPE:
//				$service = $this->rejectService;
//				break;

			case Follow::TYPE:
				$service = $this->followService;
				break;

			case Note::TYPE:
				$service = $this->noteService;
				break;

			default:
				throw new UnknownItemException();
		}

		try {
			$service->parse($activity);
		} catch (Exception $e) {
			$this->miscService->log(
				2, 'Cannot parse ' . $activity->getType() . ': ' . $e->getMessage()
			);
		}
	}


}

