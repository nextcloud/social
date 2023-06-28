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
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;

/**
 * Class LikeInterface
 *
 * @package OCA\Social\Interfaces\Object
 */
class LikeInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	private ActionsRequest $actionsRequest;
	private StreamRequest $streamRequest;
	private CacheActorService $cacheActorService;

	public function __construct(
		ActionsRequest $actionsRequest, StreamRequest $streamRequest,
		CacheActorService $cacheActorService
	) {
		$this->actionsRequest = $actionsRequest;
		$this->streamRequest = $streamRequest;
		$this->cacheActorService = $cacheActorService;
	}


	/**
	 * @throws InvalidOriginException
	 */
	public function processIncomingRequest(ACore $item): void {
		/** @var Like $like */
		$like = $item;
		$like->checkOrigin($like->getId());
		$like->checkOrigin($like->getActorId());

		try {
			$this->save($like);
		} catch (ItemAlreadyExistsException $e) {
		}
	}


	/**
	 * @throws InvalidOriginException
	 */
	public function activity(ACore $activity, ACore $item): void {
		/** @var Like $like */
		$like = $item;
		if ($activity->getType() === Undo::TYPE) {
			$activity->checkOrigin($like->getId());
			$activity->checkOrigin($like->getActorId());

			$this->delete($like);
		}
	}

	/**
	 * @throws ItemNotFoundException
	 */
	public function getItem(ACore $item): ACore {
		try {
			return $this->actionsRequest->getAction(
				$item->getActorId(),
				$item->getObjectId(),
				Like::TYPE
			);
		} catch (ActionDoesNotExistException $e) {
		}

		throw new ItemNotFoundException();
	}

	/**
	 * @throws ItemAlreadyExistsException
	 */
	public function save(ACore $item): void {
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

			$post = $this->streamRequest->getStreamById(
				$item->getObjectId(),
				false,
				ACore::FORMAT_LOCAL
			);
			$post->setCompleteDetails(true);
			$this->updateDetails($post);
			$this->generateNotification($post, $actor);
		} catch (Exception $e) {
		}
	}

	public function delete(ACore $item): void {
		$this->actionsRequest->delete($item);
		$this->undoLikeAction($item);
	}

	private function undoLikeAction(ACore $item): void {
		/** @var Like $like */
		$like = $item;
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

	private function updateDetails(Stream $post): void {
		$post->setDetailInt(
			'likes', $this->actionsRequest->countActions($post->getId(), Like::TYPE)
		);

		$this->streamRequest->updateDetails($post);
	}


	/**
	 * @throws ItemAlreadyExistsException
	 * @throws ItemNotFoundException
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	private function generateNotification(Stream $post, Person $author): void {
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
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	private function cancelNotification(Stream $post, Person $author): void {
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
