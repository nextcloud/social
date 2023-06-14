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

use OCA\Social\AP;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\PushService;
use OCA\Social\Tools\Traits\TArrayTools;

class NoteInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	use TArrayTools;

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private PushService $pushService;

	public function __construct(
		StreamRequest $streamRequest,
		CacheActorsRequest $cacheActorsRequest,
		PushService $pushService
	) {
		$this->streamRequest = $streamRequest;
		$this->cacheActorsRequest = $cacheActorsRequest;
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
			$this->updateDetails($note);
			$this->generateNotification($note);
			$this->pushService->onNewStream($note->getId());
		}
	}

	public function delete(ACore $item): void {
		/** @var Note $item */
		$this->streamRequest->deleteById($item->getId(), Note::TYPE);
	}


	public function updateDetails(Note $stream): void {
		if ($stream->getInReplyTo() === '') {
			return;
		}

		try {
			$orig = $this->streamRequest->getStreamById($stream->getInReplyTo());
			$count = $this->streamRequest->countRepliesTo($stream->getInReplyTo());
			$orig->setDetailInt('replies', $count);

			$this->streamRequest->updateDetails($orig);
		} catch (StreamNotFoundException $e) {
		}
	}

	private function generateNotification(Note $note): void {
		$mentions = $note->getTags('Mention');
		if (empty($mentions)) {
			return;
		}

		/** @var SocialAppNotificationInterface $notificationInterface */
		$notificationInterface = AP::$activityPub->getInterfaceFromType(SocialAppNotification::TYPE);
		$post = $this->streamRequest->getStreamById($note->getId(), false, ACore::FORMAT_LOCAL);

		foreach ($mentions as $mention) {
			try {
				$recipient = $this->cacheActorsRequest->getFromId($this->get('href', $mention));
				if (!$recipient->isLocal()) { // only interested on local
					throw new CacheActorDoesNotExistException();
				}
			} catch (CacheActorDoesNotExistException $e) {
				continue;
			}

			/** @var SocialAppNotification $notification */
			$notification = AP::$activityPub->getItemFromType(SocialAppNotification::TYPE);
			$notification->setDetailItem('post', $post);
			$notification->addDetail('account', $post->getActor()->getAccount());
			$notification->setAttributedTo($recipient->getId())
						 ->setSubType(Mention::TYPE)
						 ->setId($post->getId() . '/notification+mention')
						 ->setSummary('{account} mentioned you in a post')
						 ->setObjectId($post->getId())
						 ->setTo($recipient->getId())
						 ->setLocal(true);

			$notificationInterface->save($notification);
		}
	}
}
