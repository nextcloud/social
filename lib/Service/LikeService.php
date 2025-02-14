<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TStringTools;

/**
 * Class LikeService
 *
 * @package OCA\Social\Service
 */
class LikeService {
	use TStringTools;


	private StreamRequest $streamRequest;

	private StreamService $streamService;

	private SignatureService $signatureService;

	private ActivityService $activityService;

	private StreamActionService $streamActionService;

	private StreamQueueService $streamQueueService;

	private MiscService $miscService;


	/**
	 * LikeService constructor.
	 *
	 * @param StreamRequest $streamRequest
	 * @param StreamService $streamService
	 * @param SignatureService $signatureService
	 * @param ActivityService $activityService
	 * @param StreamActionService $streamActionService
	 * @param StreamQueueService $streamQueueService
	 * @param MiscService $miscService
	 */
	public function __construct(
		StreamRequest $streamRequest, StreamService $streamService, SignatureService $signatureService,
		ActivityService $activityService, StreamActionService $streamActionService,
		StreamQueueService $streamQueueService, MiscService $miscService,
	) {
		$this->streamRequest = $streamRequest;
		$this->streamService = $streamService;
		$this->signatureService = $signatureService;
		$this->activityService = $activityService;
		$this->streamActionService = $streamActionService;
		$this->streamQueueService = $streamQueueService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Person $actor
	 * @param string $postId
	 * @param string $token
	 *
	 * @return ACore
	 * @throws StreamNotFoundException
	 * @throws SocialAppConfigException
	 * @throws Exception
	 */
	public function create(Person $actor, string $postId, string &$token = ''): ACore {
		/** @var Like $like */
		$like = AP::$activityPub->getItemFromType(Like::TYPE);
		$like->setId($actor->getId() . '#like/' . $this->uuid(8));
		$like->setActor($actor);

		$note = $this->streamService->getStreamById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			throw new StreamNotFoundException('Stream is not a Note');
		}

		//		if (!$note->isPublic()) {
		//			throw new StreamNotFoundException('Stream is not Public');
		//		}

		$like->setObjectId($note->getId());
		$this->assignInstance($like, $actor, $note);

		$interface = AP::$activityPub->getInterfaceFromType(Like::TYPE);
		$interface->save($like);

		$this->streamActionService->setActionBool($actor->getId(), $postId, StreamAction::LIKED, true);
		$token = $this->activityService->request($like);

		return $like;
	}


	/**
	 * @param string $postId
	 *
	 * @return Stream
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 */
	public function get(string $postId): Stream {
		$stream = $this->streamRequest->getStreamByObjectId($postId, Like::TYPE);

		return $stream;
	}


	/**
	 * @param Person $actor
	 * @param string $postId
	 * @param string $token
	 *
	 * @return ACore
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 */
	public function delete(Person $actor, string $postId, string &$token = ''): ACore {
		$undo = new Undo();
		$undo->setActor($actor);

		$note = $this->streamService->getStreamById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			throw new StreamNotFoundException('Stream is not a Note');
		}

		$this->assignInstance($undo, $actor, $note);
		try {
			$tmp = AP::$activityPub->getItemFromType(Like::TYPE);
			$tmp->setActor($actor);
			$tmp->setObjectId($postId);

			$interface = AP::$activityPub->getInterfaceFromType(Like::TYPE);
			$like = $interface->getItem($tmp);

			$undo->setId($like->getId() . '/undo');
			$undo->setObject($like);

			$interface->delete($like);

			$token = $this->activityService->request($undo);
		} catch (ItemUnknownException $e) {
		} catch (ItemNotFoundException $e) {
		}

		$this->streamActionService->setActionBool($actor->getId(), $postId, StreamAction::LIKED, false);

		return $undo;
	}


	/**
	 * @param ACore $item
	 * @param Person $actor
	 * @param Stream $note
	 */
	private function assignInstance(ACore $item, Person $actor, Stream $note) {
		//		$item->addInstancePath(
		//			new InstancePath(
		//				$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
		//			)
		//		);
		$item->addInstancePath(
			new InstancePath(
				$note->getAttributedTo(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_LOW
			)
		);
	}
}
