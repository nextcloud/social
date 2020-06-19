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


use daita\MySmallPhpTools\Exceptions\CacheItemNotFoundException;
use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use daita\MySmallPhpTools\Exceptions\RequestContentException;
use daita\MySmallPhpTools\Exceptions\RequestNetworkException;
use daita\MySmallPhpTools\Exceptions\RequestResultNotJsonException;
use daita\MySmallPhpTools\Exceptions\RequestResultSizeException;
use daita\MySmallPhpTools\Exceptions\RequestServerException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\StreamQueueService;


/**
 * Class AnnounceInterface
 *
 * @package OCA\Social\Interfaces\Object
 */
class AnnounceInterface implements IActivityPubInterface {


	use TArrayTools;


	/** @var StreamRequest */
	private $streamRequest;

	/** @var ActionsRequest */
	private $actionsRequest;

	/** @var StreamQueueService */
	private $streamQueueService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var MiscService */
	private $miscService;


	/**
	 * AnnounceInterface constructor.
	 *
	 * @param StreamRequest $streamRequest
	 * @param ActionsRequest $actionsRequest
	 * @param StreamQueueService $streamQueueService
	 * @param CacheActorService $cacheActorService
	 * @param MiscService $miscService
	 */
	public function __construct(
		StreamRequest $streamRequest, ActionsRequest $actionsRequest,
		StreamQueueService $streamQueueService, CacheActorService $cacheActorService,
		MiscService $miscService
	) {
		$this->streamRequest = $streamRequest;
		$this->actionsRequest = $actionsRequest;
		$this->streamQueueService = $streamQueueService;
		$this->cacheActorService = $cacheActorService;
		$this->miscService = $miscService;
	}


	/**
	 * @param ACore $item
	 *
	 * @throws InvalidOriginException
	 * @throws Exception
	 */
	public function processIncomingRequest(ACore $item) {
		/** @var ACore $item */
		$item->checkOrigin($item->getId());
		$item->checkOrigin($item->getActorId());

		try {
			$this->actionsRequest->getActionFromItem($item);
		} catch (ActionDoesNotExistException $e) {
			$this->actionsRequest->save($item);

			try {
				$post = $this->streamRequest->getStreamById($item->getObjectId());
				$this->updateDetails($post);
			} catch (Exception $e) {
			}
		}

		$this->save($item);
	}


	/**
	 * @param ACore $activity
	 * @param ACore $announce
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws UnauthorizedFediverseException
	 */
	public function activity(Acore $activity, ACore $announce) {
		/** @var Announce $announce */
		if ($activity->getType() === Undo::TYPE) {
			$activity->checkOrigin($announce->getId());
			$activity->checkOrigin($announce->getActorId());

			$this->undoAnnounceAction($announce);
			$this->delete($announce);
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
	 * @throws Exception
	 */
	public function save(ACore $item) {
		/** @var Announce $item */

		try {
			$knownItem = $this->streamRequest->getStreamByObjectId($item->getObjectId(), Announce::TYPE);

			if ($item->hasActor()) {
				$actor = $item->getActor();
			} else {
				$actor = $this->cacheActorService->getFromId($item->getActorId());
			}

			$knownItem->setAttributedTo($actor->getId());
			if (!$knownItem->hasCc($actor->getFollowers())) {
				$knownItem->addCc($actor->getFollowers());
				$this->streamRequest->update($knownItem);
			}

			try {
				$post = $this->streamRequest->getStreamById($item->getObjectId());
			} catch (StreamNotFoundException $e) {
				return; // should not happens.
			}

			$this->updateDetails($post);
			$this->generateNotification($post, $actor);
		} catch (StreamNotFoundException $e) {
			$objectId = $item->getObjectId();
			$item->addCacheItem($objectId);
			$item->setAttributedTo($item->getActorId());
			$this->streamRequest->save($item);

			$this->streamQueueService->generateStreamQueue(
				$item->getRequestToken(), StreamQueue::TYPE_CACHE, $item->getId()
			);
		}

	}


	/**
	 * @param ACore $item
	 */
	public function update(ACore $item) {
	}


	/**
	 * @param ACore $item
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws UnauthorizedFediverseException
	 * @throws MalformedArrayException
	 */
	public function delete(ACore $item) {
		try {
			$knownItem =
				$this->streamRequest->getStreamByObjectId($item->getObjectId(), Announce::TYPE);

			if ($item->hasActor()) {
				$actor = $item->getActor();
			} else {
				$actor = $this->cacheActorService->getFromId($item->getActorId());
			}

			$knownItem->removeCc($actor->getFollowers());

			if (empty($knownItem->getCcArray())) {
				$this->streamRequest->deleteById($knownItem->getId(), Announce::TYPE);
			} else {
				$this->streamRequest->update($knownItem);
			}
		} catch (StreamNotFoundException $e) {
		} catch (ItemUnknownException $e) {
		} catch (SocialAppConfigException $e) {
		}
	}


	/**
	 * @param ACore $item
	 * @param string $source
	 */
	public function event(ACore $item, string $source) {
		/** @var Stream $item */
		switch ($source) {
			case 'updateCache':
				$objectId = $item->getObjectId();
				try {
					$cachedItem = $item->getCache()
									   ->getItem($objectId);
				} catch (CacheItemNotFoundException $e) {
					return;
				}

				$to = $this->get('attributedTo', $cachedItem->getObject(), '');
				if ($to !== '') {
					$this->streamRequest->updateAttributedTo($item->getId(), $to);
				}

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

				break;
		}
	}


	/**
	 * @param Announce $announce
	 */
	private function undoAnnounceAction(Announce $announce) {
		try {
			$this->actionsRequest->getActionFromItem($announce);
			$this->actionsRequest->delete($announce);
		} catch (ActionDoesNotExistException $e) {
		}

		try {
			if ($announce->hasActor()) {
				$actor = $announce->getActor();
			} else {
				$actor = $this->cacheActorService->getFromId($announce->getActorId());
			}

			$post = $this->streamRequest->getStreamById($announce->getObjectId());
			$this->updateDetails($post);
			$this->cancelNotification($post, $actor);
		} catch (Exception $e) {
		}
	}


	/**
	 * @param Stream $post
	 */
	private function updateDetails(Stream $post) {
		$post->setDetailInt(
			'boosts', $this->actionsRequest->countActions($post->getId(), Announce::TYPE)
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
				$post->getId(), SocialAppNotification::TYPE, Announce::TYPE
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
						 ->setSubType(Announce::TYPE)
						 ->setId($post->getId() . '/notification+boost')
						 ->setSummary('{accounts} boosted your post')
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
				$post->getId(), SocialAppNotification::TYPE, Announce::TYPE
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

