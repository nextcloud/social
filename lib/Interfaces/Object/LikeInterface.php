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
use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\MiscService;


/**
 * Class LikeInterface
 *
 * @package OCA\Social\Interfaces\Object
 */
class LikeInterface implements IActivityPubInterface {

	/** @var ActionsRequest */
	private $actionsRequest;

	/** @var StreamRequest */
	private $streamRequest;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var MiscService */
	private $miscService;


	/**
	 * LikeService constructor.
	 *
	 * @param ActionsRequest $actionsRequest
	 * @param StreamRequest $streamRequest
	 * @param CacheActorService $cacheActorService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActionsRequest $actionsRequest, StreamRequest $streamRequest,
		CacheActorService $cacheActorService, MiscService $miscService
	) {
		$this->actionsRequest = $actionsRequest;
		$this->streamRequest = $streamRequest;
		$this->cacheActorService = $cacheActorService;
		$this->miscService = $miscService;
	}


	/**
	 * @param ACore $like
	 *
	 * @throws InvalidOriginException
	 */
	public function processIncomingRequest(ACore $like) {
		/** @var Like $like */
		$like->checkOrigin($like->getId());
		$like->checkOrigin($like->getActorId());

		try {
			$this->save($like);
		} catch (ItemAlreadyExistsException $e) {
		}
	}


	/**
	 * @param ACore $activity
	 * @param ACore $like
	 *
	 * @throws InvalidOriginException
	 */
	public function activity(ACore $activity, ACore $like) {
		/** @var Like $like */
		if ($activity->getType() === Undo::TYPE) {
			$activity->checkOrigin($like->getId());
			$activity->checkOrigin($like->getActorId());

			$this->delete($like);
		}
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
		try {
			return $this->actionsRequest->getAction(
				$item->getActorId(), $item->getObjectId(), Like::TYPE
			);
		} catch (ActionDoesNotExistException $e) {
		}

		throw new ItemNotFoundException();
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
	 * @throws ItemAlreadyExistsException
	 */
	public function save(ACore $item) {
		try {
			$this->actionsRequest->getActionFromItem($item);
			throw new ItemAlreadyExistsException();
		} catch (ActionDoesNotExistException $e) {
		}

		$this->actionsRequest->save($item);
		try {
			if ($item->hasActor()) {
				$actor = $item->getActor();
			} else {
				$actor = $this->cacheActorService->getFromId($item->getActorId());
			}

			$post = $this->streamRequest->getStreamById($item->getObjectId());
			$this->updateDetails($post);
			$this->generateNotification($post, $actor);
		} catch (Exception $e) {
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
		$this->actionsRequest->delete($item);
		$this->undoLikeAction($item);
	}


	/**
	 * @param ACore $item
	 * @param string $source
	 */
	public function event(ACore $item, string $source) {
	}


	/**
	 * @param ACore $like
	 */
	private function undoLikeAction(ACore $like) {
		try {
			if ($like->hasActor()) {
				$actor = $like->getActor();
			} else {
				$actor = $this->cacheActorService->getFromId($like->getActorId());
			}

			$post = $this->streamRequest->getStreamById($like->getObjectId());
			$this->updateDetails($post);
			$this->cancelNotification($post, $actor);
		} catch (Exception $e) {
		}
	}


	/**
	 * @param Stream $post
	 */
	private function updateDetails(Stream $post) {
//		if (!$post->isLocal()) {
//			return;
//		}

		$post->setDetailInt(
			'likes', $this->actionsRequest->countActions($post->getId(), Like::TYPE)
		);

		$this->streamRequest->update($post);
	}


	/**
	 * @param Stream $post
	 * @param Person $author
	 *
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	private function generateNotification(Stream $post, Person $author) {
		if (!$post->isLocal()) {
			return;
		}

		/** @var SocialAppNotificationInterface $notificationInterface */
		$notificationInterface =
			AP::$activityPub->getInterfaceFromType(SocialAppNotification::TYPE);

		try {
			$notification = $this->streamRequest->getStreamByObjectId(
				$post->getId(), SocialAppNotification::TYPE, Like::TYPE
			);

			$notification->addDetail('accounts', $author->getAccount());
			$notificationInterface->update($notification);
		} catch (StreamNotFoundException $e) {

			/** @var SocialAppNotification $notification */
			$notification = AP::$activityPub->getItemFromType(SocialAppNotification::TYPE);
//			$notification->setDetail('url', '');
			$notification->setDetailItem('post', $post);
			$notification->addDetail('accounts', $author->getAccount());
			$notification->setAttributedTo($author->getId())
						 ->setSubType(Like::TYPE)
						 ->setId($post->getId() . '/notification+like')
						 ->setSummary('{accounts} liked your post')
						 ->setObjectId($post->getId())
						 ->setTo($post->getAttributedTo())
						 ->setLocal(true);

			$notificationInterface->save($notification);
		}
	}


	/**
	 * @param Stream $post
	 * @param Person $author
	 *
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	private function cancelNotification(Stream $post, Person $author) {
		if (!$post->isLocal()) {
			return;
		}

		/** @var SocialAppNotificationInterface $notificationInterface */
		$notificationInterface =
			AP::$activityPub->getInterfaceFromType(SocialAppNotification::TYPE);

		try {
			$notification = $this->streamRequest->getStreamByObjectId(
				$post->getId(), SocialAppNotification::TYPE, Like::TYPE
			);

			$notification->removeDetail('accounts', $author->getAccount());
			if (empty($notification->getDetails('accounts'))) {
				$notificationInterface->delete($notification);
			} else {
				$notificationInterface->update($notification);
			}
		} catch (StreamNotFoundException $e) {
		}
	}
}

