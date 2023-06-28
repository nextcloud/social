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
use OCA\Social\Interfaces\Actor\ApplicationInterface;
use OCA\Social\Interfaces\Actor\GroupInterface;
use OCA\Social\Interfaces\Actor\OrganizationInterface;
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
use OCA\Social\Model\ActivityPub\Actor\Application;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Organization;
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
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\AppFramework\QueryException;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Class AP
 *
 * @package OCA\Social
 */
class AP {
	use TArrayTools;

	public const REDUNDANCY_LIMIT = 10;

	public AcceptInterface $acceptInterface;
	public AddInterface $addInterface;
	public AnnounceInterface $announceInterface;
	public BlockInterface $blockInterface;
	public CreateInterface $createInterface;
	public DeleteInterface $deleteInterface;
	public DocumentInterface $documentInterface;
	public FollowInterface $followInterface;
	public ImageInterface $imageInterface;
	public LikeInterface $likeInterface;
	public PersonInterface $personInterface;
	public NoteInterface $noteInterface;
	public GroupInterface $groupInterface;
	public OrganizationInterface $organizationInterface;
	public ApplicationInterface $applicationInterface;
	public RejectInterface $rejectInterface;
	public RemoveInterface $removeInterface;
	public ServiceInterface $serviceInterface;
	public UndoInterface $undoInterface;
	public UpdateInterface $updateInterface;
	public SocialAppNotificationInterface $notificationInterface;
	public ConfigService $configService;
	public static ?AP $activityPub = null;

	public function __construct(
		AcceptInterface $acceptInterface,
		AddInterface $addInterface,
		AnnounceInterface $announceInterface,
		BlockInterface $blockInterface,
		CreateInterface $createInterface,
		DeleteInterface $deleteInterface,
		DocumentInterface $documentInterface,
		FollowInterface $followInterface,
		ImageInterface $imageInterface,
		LikeInterface $likeInterface,
		NoteInterface $noteInterface,
		SocialAppNotificationInterface $notificationInterface,
		PersonInterface $personInterface,
		ServiceInterface $serviceInterface,
		GroupInterface $groupInterface,
		OrganizationInterface $organizationInterface,
		ApplicationInterface $applicationInterface,
		RejectInterface $rejectInterface,
		RemoveInterface $removeInterface,
		UndoInterface $undoInterface,
		UpdateInterface $updateInterface,
		ConfigService $configService
	) {
		$this->acceptInterface = $acceptInterface;
		$this->addInterface = $addInterface;
		$this->announceInterface = $announceInterface;
		$this->blockInterface = $blockInterface;
		$this->createInterface = $createInterface;
		$this->deleteInterface = $deleteInterface;
		$this->documentInterface = $documentInterface;
		$this->followInterface = $followInterface;
		$this->imageInterface = $imageInterface;
		$this->likeInterface = $likeInterface;
		$this->noteInterface = $noteInterface;
		$this->notificationInterface = $notificationInterface;
		$this->personInterface = $personInterface;
		$this->serviceInterface = $serviceInterface;
		$this->groupInterface = $groupInterface;
		$this->organizationInterface = $organizationInterface;
		$this->applicationInterface = $applicationInterface;
		$this->rejectInterface = $rejectInterface;
		$this->removeInterface = $removeInterface;
		$this->undoInterface = $undoInterface;
		$this->updateInterface = $updateInterface;
		$this->configService = $configService;
	}

	public static function init() {
		try {
			AP::$activityPub = Server::get(AP::class);
		} catch (QueryException $e) {
			Server::get(LoggerInterface::class)
					   ->error($e->getMessage(), ['exception' => $e]);
		}
	}


	/**
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 */
	public function getItemFromData(array $data, ACore $parent = null, int $level = 0): ACore {
		if (++$level > self::REDUNDANCY_LIMIT) {
			throw new RedundancyLimitException((string)$level);
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

			case OrderedCollection::TYPE:
				$item = new OrderedCollection();
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

			case Group::TYPE:
				$item = new Group();
				break;

			case Organization::TYPE:
				$item = new Organization();
				break;

			case Application::TYPE:
				$item = new Application();
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
				return $this->acceptInterface;

			case Add::TYPE:
				return $this->addInterface;

			case Announce::TYPE:
				return $this->announceInterface;

			case Block::TYPE:
				return $this->blockInterface;

			case Create::TYPE:
				return $this->createInterface;

			case Delete::TYPE:
				return $this->deleteInterface;

			case Document::TYPE:
				return $this->documentInterface;

			case Follow::TYPE:
				return $this->followInterface;

			case Image::TYPE:
				return $this->imageInterface;

			case Like::TYPE:
				return $this->likeInterface;

			case Note::TYPE:
				return $this->noteInterface;

			case SocialAppNotification::TYPE:
				return $this->notificationInterface;

			case Person::TYPE:
				return $this->personInterface;

			case Reject::TYPE:
				return $this->rejectInterface;

			case Remove::TYPE:
				return $this->removeInterface;

			case Service::TYPE:
				return $this->serviceInterface;

			case Undo::TYPE:
				return $this->undoInterface;

			case Update::TYPE:
				return $this->updateInterface;

			default:
				throw new ItemUnknownException();
		}
	}

	public function isActor(ACore $item): bool {
		$types = [
			Person::TYPE,
			Service::TYPE,
			Group::TYPE,
			Organization::TYPE,
			Application::TYPE
		];

		return (in_array($item->getType(), $types));
	}
}

AP::init();
