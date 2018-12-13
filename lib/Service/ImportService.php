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
use OCA\Social\Exceptions\ActivityPubFormatException;
use OCA\Social\Exceptions\InvalidResourceEntryException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Block;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Follow;
use OCA\Social\Model\ActivityPub\Activity\Like;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Remove;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Service\ActivityPub\Activity\AcceptService;
use OCA\Social\Service\ActivityPub\Activity\AddService;
use OCA\Social\Service\ActivityPub\Activity\BlockService;
use OCA\Social\Service\ActivityPub\Activity\CreateService;
use OCA\Social\Service\ActivityPub\Activity\DeleteService;
use OCA\Social\Service\ActivityPub\Activity\FollowService;
use OCA\Social\Service\ActivityPub\Activity\LikeService;
use OCA\Social\Service\ActivityPub\Activity\RejectService;
use OCA\Social\Service\ActivityPub\Activity\RemoveService;
use OCA\Social\Service\ActivityPub\Activity\UndoService;
use OCA\Social\Service\ActivityPub\Activity\UpdateService;
use OCA\Social\Service\ActivityPub\ICoreService;
use OCA\Social\Service\ActivityPub\Object\NoteService;
use OCA\Social\Service\ActivityPub\Actor\PersonService;


class ImportService {


	use TArrayTools;

	/** @var AcceptService */
	private $acceptService;

	/** @var AddService */
	private $addService;

	/** @var BlockService */
	private $blockService;

	/** @var CreateService */
	private $createService;

	/** @var DeleteService */
	private $deleteService;

	/** @var FollowService */
	private $followService;

	/** @var LikeService */
	private $likeService;

	/** @var PersonService */
	private $personService;

	/** @var NoteService */
	private $noteService;

	/** @var RejectService */
	private $rejectService;

	/** @var RemoveService */
	private $removeService;

	/** @var UndoService */
	private $undoService;

	/** @var UpdateService */
	private $updateService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ImportService constructor.
	 *
	 * @param AcceptService $acceptService
	 * @param AddService $addService
	 * @param BlockService $blockService
	 * @param CreateService $createService
	 * @param DeleteService $deleteService
	 * @param FollowService $followService
	 * @param NoteService $noteService
	 * @param LikeService $likeService
	 * @param PersonService $personService
	 * @param RejectService $rejectService
	 * @param RemoveService $removeService
	 * @param UndoService $undoService
	 * @param UpdateService $updateService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		AcceptService $acceptService, AddService $addService, BlockService $blockService,
		CreateService $createService, DeleteService $deleteService, FollowService $followService,
		NoteService $noteService, LikeService $likeService, PersonService $personService,
		RejectService $rejectService, RemoveService $removeService,
		UndoService $undoService, UpdateService $updateService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->acceptService = $acceptService;
		$this->addService = $addService;
		$this->blockService = $blockService;
		$this->createService = $createService;
		$this->deleteService = $deleteService;
		$this->followService = $followService;
		$this->likeService = $likeService;
		$this->rejectService = $rejectService;
		$this->removeService = $removeService;
		$this->personService = $personService;
		$this->noteService = $noteService;
		$this->undoService = $undoService;
		$this->updateService = $updateService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $json
	 *
	 * @return ACore
	 * @throws UnknownItemException
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 * @throws ActivityPubFormatException
	 */
	public function importFromJson(string $json) {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			throw new ActivityPubFormatException();
		}
		$activity = $this->importFromData($data, null);

		return $activity;
	}


	/**
	 * @param array $data
	 * @param ACore $root
	 *
	 * @return ACore
	 * @throws UnknownItemException
	 * @throws UrlCloudException
	 * @throws SocialAppConfigException
	 * @throws InvalidResourceEntryException
	 */
	private function importFromData(array $data, $root = null): ACore {

		// TODO - missing : Person (why not ?), OrderCollection (not yet), Document (should ?)
		switch ($this->get('type', $data, '')) {
			case Accept::TYPE:
				$item = new Accept($root);
				break;

			case Add::TYPE:
				$item = new Add($root);
				break;

			case Block::TYPE:
				$item = new Block($root);
				break;

			case Create::TYPE:
				$item = new Create($root);
				break;

			case Delete::TYPE:
				$item = new Delete($root);
				break;

			case Follow::TYPE:
				$item = new Follow($root);
				break;

			case Image::TYPE:
				$item = new Image($root);
				break;

			case Like::TYPE:
				$item = new Like($root);
				break;

			case Note::TYPE:
				$item = new Note($root);
				break;

			case Person::TYPE:
				$item = new Note($root);
				break;

			case Reject::TYPE:
				$item = new Reject($root);
				break;

			case Remove::TYPE:
				$item = new Remove($root);
				break;

			case Tombstone::TYPE:
				$item = new Tombstone($root);
				break;

			case Undo::TYPE:
				$item = new Undo($root);
				break;

			case Update::TYPE:
				$item = new Update($root);
				break;

			default:
				throw new UnknownItemException();
		}

		$item->setUrlCloud($this->configService->getCloudAddress());
		$item->import($data);
		$item->setSource(json_encode($data, JSON_UNESCAPED_SLASHES));

		try {
			$object = $this->importFromData($this->getArray('object', $data, []), $item);
			$item->setObject($object);
		} catch (UnknownItemException $e) {
		}

		try {
			/** @var Document $icon */
			$icon = $this->importFromData($this->getArray('icon', $data, []), $item);
			$item->setIcon($icon);
		} catch (UnknownItemException $e) {
		}

		return $item;
	}


	/**
	 * @param ACore $activity
	 *
	 * @throws UnknownItemException
	 */
	public function parseIncomingRequest(ACore $activity) {

		// not sure we need to recursive on activity/parsing.
//		if ($activity->gotObject()) {
//			try {
//				$this->parseIncomingRequest($activity->getObject());
//			} catch (UnknownItemException $e) {
//			}
//		}

		$service = $this->getServiceForItem($activity);

		try {
			$service->processIncomingRequest($activity, $this);
		} catch (Exception $e) {
			$this->miscService->log(
				'Cannot parse ' . $activity->getType() . ': ' . $e->getMessage()
			);
		}
	}


	/**
	 * @param ACore $activity
	 *
	 * @return ICoreService
	 * @throws UnknownItemException
	 */
	public function getServiceForItem(Acore $activity): ICoreService {
		switch ($activity->getType()) {

			case Accept::TYPE:
				$service = $this->acceptService;
				break;

			case Add::TYPE:
				$service = $this->addService;
				break;

			case Block::TYPE:
				$service = $this->blockService;
				break;

			case Create::TYPE:
				$service = $this->createService;
				break;

			case Delete::TYPE:
				$service = $this->deleteService;
				break;

			case Follow::TYPE:
				$service = $this->followService;
				break;

			case Like::TYPE:
				$service = $this->likeService;
				break;

			case Note::TYPE:
				$service = $this->noteService;
				break;

			case Person::TYPE:
				$service = $this->personService;
				break;

			case Reject::TYPE:
				$service = $this->rejectService;
				break;

			case Remove::TYPE:
				$service = $this->removeService;
				break;

			case Undo::TYPE:
				$service = $this->undoService;
				break;

			case Update::TYPE:
				$service = $this->updateService;
				break;

			default:
				throw new UnknownItemException();
		}

		return $service;
	}


}

