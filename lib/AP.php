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


namespace OCA\Social;


use daita\MySmallPhpTools\Traits\TArrayTools;
use OC;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Interfaces\Activity\AcceptInterface;
use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\Activity\BlockInterface;
use OCA\Social\Interfaces\Activity\CreateInterface;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Interfaces\Activity\RejectInterface;
use OCA\Social\Interfaces\Activity\RemoveInterface;
use OCA\Social\Interfaces\Activity\UndoInterface;
use OCA\Social\Interfaces\Activity\UpdateInterface;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Interfaces\Actor\ServiceInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Interfaces\Object\AnnounceInterface;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Block;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Remove;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Actor\Service;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\QueryException;


/**
 * Class AP
 *
 * @package OCA\Social
 */
class AP {


	use TArrayTools;


	const REDUNDANCY_LIMIT = 10;


	/** @var AcceptInterface */
	public $acceptInterface;

	/** @var AddInterface */
	public $addInterface;

	/** @var AnnounceInterface */
	public $announceInterface;

	/** @var BlockInterface */
	public $blockInterface;

	/** @var CreateInterface */
	public $createInterface;

	/** @var DeleteInterface */
	public $deleteInterface;

	/** @var DocumentInterface */
	public $documentInterface;

	/** @var FollowInterface */
	public $followInterface;

	/** @var ImageInterface */
	public $imageInterface;

	/** @var LikeInterface */
	public $likeInterface;

	/** @var PersonInterface */
	public $personInterface;

	/** @var NoteInterface */
	public $noteInterface;

	/** @var RejectInterface */
	public $rejectInterface;

	/** @var RemoveInterface */
	public $removeInterface;

	/** @var ServiceInterface */
	public $serviceInterface;

	/** @var UndoInterface */
	public $undoInterface;

	/** @var UpdateInterface */
	public $updateInterface;

	/** @var SocialAppNotificationInterface */
	public $notificationInterface;

	/** @var ConfigService */
	public $configService;


	/** @var AP */
	public static $activityPub = null;


	/**
	 * AP constructor.
	 */
	public function __construct() {
	}


	/**
	 *
	 */
	public static function init() {
		$ap = new AP();
		try {
			$ap->acceptInterface = OC::$server->query(AcceptInterface::class);
			$ap->addInterface = OC::$server->query(AddInterface::class);
			$ap->announceInterface = OC::$server->query(AnnounceInterface::class);
			$ap->blockInterface = OC::$server->query(BlockInterface::class);
			$ap->createInterface = OC::$server->query(CreateInterface::class);
			$ap->deleteInterface = OC::$server->query(DeleteInterface::class);
			$ap->documentInterface = OC::$server->query(DocumentInterface::class);
			$ap->followInterface = OC::$server->query(FollowInterface::class);
			$ap->imageInterface = OC::$server->query(ImageInterface::class);
			$ap->likeInterface = OC::$server->query(LikeInterface::class);
			$ap->noteInterface = OC::$server->query(NoteInterface::class);
			$ap->notificationInterface = OC::$server->query(SocialAppNotificationInterface::class);
			$ap->personInterface = OC::$server->query(PersonInterface::class);
			$ap->serviceInterface = OC::$server->query(ServiceInterface::class);
			$ap->rejectInterface = OC::$server->query(RejectInterface::class);
			$ap->removeInterface = OC::$server->query(RemoveInterface::class);
			$ap->undoInterface = OC::$server->query(UndoInterface::class);
			$ap->updateInterface = OC::$server->query(UpdateInterface::class);

			$ap->configService = OC::$server->query(ConfigService::class);

			AP::$activityPub = $ap;
		} catch (QueryException $e) {
			OC::$server->getLogger()
					   ->logException($e);
		}
	}


	/**
	 * @param array $data
	 * @param ACore $parent
	 * @param int $level
	 *
	 * @return ACore
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 */
	public function getItemFromData(array $data, ACore $parent = null, int $level = 0): ACore {
		if (++$level > self::REDUNDANCY_LIMIT) {
			throw new RedundancyLimitException($level);
		}

		$item = $this->getSimpleItemFromData($data);
		if ($parent !== null) {
			$item->setParent($parent);
		}

		$this->getObjectFromData($data, $item, $level);
		$this->getActorFromData($data, $item, $level);

		return $item;
	}


	/**
	 * @param array $data
	 * @param ACore $item
	 * @param int $level
	 *
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 */
	public function getObjectFromData(array $data, ACore &$item, int $level) {
		try {
			$objectData = $this->getArray('object', $data, []);
			if (empty($objectData)) {
				$objectId = $this->get('object', $data, '');
				if ($objectId !== '') {
					// TODO: validate AS_URL
					$item->setObjectId($objectId);
				}
			} else {
				$object = $this->getItemFromData($objectData, $item, $level);
				$item->setObject($object);
			}
		} catch (ItemUnknownException $e) {
		}
	}


	/**
	 * @param array $data
	 * @param ACore $item
	 * @param int $level
	 *
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 */
	public function getActorFromData(array $data, ACore &$item, int $level) {
		try {
			$actorData = $this->getArray('actor_info', $data, []);
			if (!empty($actorData)) {
				/** @var Person $actor */
				$actor = $this->getItemFromData($actorData, $item, $level);
				$item->setActor($actor);
			}
		} catch (ItemUnknownException $e) {
		}
	}


	/**
	 * @param array $data
	 *
	 * @return ACore
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 */
	public function getSimpleItemFromData(array $data): Acore {
		$item = $this->getItemFromType($this->get('type', $data, ''));
		$item->import($data);
		$item->setSource(json_encode($data, JSON_UNESCAPED_SLASHES));

		return $item;
	}

	/**
	 * @param string $type
	 *
	 * @return ACore
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	public function getItemFromType(string $type): ACore {

		switch ($type) {
			case Accept::TYPE:
				$item = new Accept();
				break;

			case Add::TYPE:
				$item = new Add();
				break;

			case Announce::TYPE:
				$item = new Announce();
				$item->setFilterDuplicate(true);
				break;

			case Block::TYPE:
				$item = new Block();
				break;

			case Create::TYPE:
				$item = new Create();
				break;

			case Delete::TYPE:
				$item = new Delete();
				break;

			case Document::TYPE:
				$item = new Document();
				break;

			case Follow::TYPE:
				$item = new Follow();
				break;

			case Image::TYPE:
				$item = new Image();
				break;

			case Like::TYPE:
				$item = new Like();
				break;

			case Note::TYPE:
				$item = new Note();
				break;

			case SocialAppNotification::TYPE:
				$item = new SocialAppNotification();
				break;

			case Stream::TYPE:
				$item = new Stream();
				break;

			case Person::TYPE:
				$item = new Person();
				break;

			case Reject::TYPE:
				$item = new Reject();
				break;

			case Remove::TYPE:
				$item = new Remove();
				break;

			case Service::TYPE:
				$item = new Service();
				break;

			case Tombstone::TYPE:
				$item = new Tombstone();
				break;

			case Undo::TYPE:
				$item = new Undo();
				break;

			case Update::TYPE:
				$item = new Update();
				break;

			default:
				throw new ItemUnknownException();
		}

		$item->setUrlCloud($this->configService->getCloudUrl());

		return $item;
	}


	/**
	 * @param ACore $activity
	 *
	 * @return IActivityPubInterface
	 * @throws ItemUnknownException
	 */
	public function getInterfaceForItem(Acore $activity): IActivityPubInterface {
		return $this->getInterfaceFromType($activity->getType());
	}


	/**
	 * @param string $type
	 *
	 * @return IActivityPubInterface
	 * @throws ItemUnknownException
	 */
	public function getInterfaceFromType(string $type): IActivityPubInterface {
		switch ($type) {
			case Accept::TYPE:
				$interface = $this->acceptInterface;
				break;

			case Add::TYPE:
				$interface = $this->addInterface;
				break;

			case Announce::TYPE:
				$interface = $this->announceInterface;
				break;

			case Block::TYPE:
				$interface = $this->blockInterface;
				break;

			case Create::TYPE:
				$interface = $this->createInterface;
				break;

			case Delete::TYPE:
				$interface = $this->deleteInterface;
				break;

			case Document::TYPE:
				$interface = $this->documentInterface;
				break;

			case Follow::TYPE:
				$interface = $this->followInterface;
				break;

			case Image::TYPE:
				$interface = $this->imageInterface;
				break;

			case Like::TYPE:
				$interface = $this->likeInterface;
				break;

			case Note::TYPE:
				$interface = $this->noteInterface;
				break;

			case SocialAppNotification::TYPE:
				$interface = $this->notificationInterface;
				break;

			case Person::TYPE:
				$interface = $this->personInterface;
				break;

			case Reject::TYPE:
				$interface = $this->rejectInterface;
				break;

			case Remove::TYPE:
				$interface = $this->removeInterface;
				break;

			case Service::TYPE:
				$interface = $this->serviceInterface;
				break;

			case Undo::TYPE:
				$interface = $this->undoInterface;
				break;

			case Update::TYPE:
				$interface = $this->updateInterface;
				break;

			default:
				throw new ItemUnknownException();
		}

		return $interface;
	}


	/**
	 * @param ACore $item
	 *
	 * @return bool
	 */
	public function isActor(ACore $item): bool {
		$types =
			[
				Person::TYPE,
				Service::TYPE
			];

		return (in_array($item->getType(), $types));
	}

}


AP::init();

