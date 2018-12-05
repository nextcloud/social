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
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Tombstone;
use OCA\Social\Model\ActivityPub\Document;
use OCA\Social\Model\ActivityPub\Follow;
use OCA\Social\Model\ActivityPub\Image;
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

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * ImportService constructor.
	 *
	 * @param NoteService $noteService
	 * @param UndoService $undoService
	 * @param FollowService $followService
	 * @param DeleteService $deleteService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NoteService $noteService, UndoService $undoService, FollowService $followService,
		DeleteService $deleteService, ConfigService $configService, MiscService $miscService
	) {
		$this->noteService = $noteService;
		$this->undoService = $undoService;
		$this->followService = $followService;
		$this->deleteService = $deleteService;
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

			case Note::TYPE:
				$item = new Note($root);
				break;

			case Reject::TYPE:
				$item = new Reject($root);
				break;

			case Tombstone::TYPE:
				$item = new Tombstone($root);
				break;

			case Undo::TYPE:
				$item = new Undo($root);
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

		if ($activity->gotObject()) {
			try {
				$this->parseIncomingRequest($activity->getObject());
			} catch (UnknownItemException $e) {
			}
		}

		switch ($activity->getType()) {

			case Delete::TYPE:
				$service = $this->deleteService;
				break;

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


	/**
	 * @param ACore $activity
	 *
	 * @param string $id
	 *
	 * @throws InvalidResourceException
	 */
	public function verifyOrigin(ACore $activity, string $id) {
		throw new InvalidResourceException();
	}

}

