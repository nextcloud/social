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
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TStringTools;

/**
 * Class BoostService
 *
 * @package OCA\Social\Service
 */
class BoostService {
	use TStringTools;

	private StreamRequest $streamRequest;
	private StreamService $streamService;
	private SignatureService $signatureService;
	private ActivityService $activityService;
	private StreamActionService $streamActionService;
	private StreamQueueService $streamQueueService;

	public function __construct(
		StreamRequest $streamRequest, StreamService $streamService, SignatureService $signatureService,
		ActivityService $activityService, StreamActionService $streamActionService,
		StreamQueueService $streamQueueService,
	) {
		$this->streamRequest = $streamRequest;
		$this->streamService = $streamService;
		$this->signatureService = $signatureService;
		$this->activityService = $activityService;
		$this->streamActionService = $streamActionService;
		$this->streamQueueService = $streamQueueService;
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
		/** @var Announce $announce */
		$announce = AP::$activityPub->getItemFromType(Announce::TYPE);
		$this->streamService->assignItem($announce, $actor, Stream::TYPE_ANNOUNCE);
		$announce->setActor($actor);

		$note = $this->streamService->getStreamById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			throw new StreamNotFoundException('Stream is not a Note');
		}

		if (!$note->isPublic()) {
			throw new StreamNotFoundException('Stream is not Public');
		}

		$announce->setTo(ACore::CONTEXT_PUBLIC);
		$announce->addCc($actor->getFollowers());
		//	$announce->addcc($note->getAttributedTo());

		$announce->setObjectId($note->getId());
		$announce->setRequestToken($this->uuid());

		$interface = AP::$activityPub->getInterfaceFromType(Announce::TYPE);
		// TODO: check that announce does not exist already ?
		//		try {
		//			return $interface->getItem($announce);
		//		} catch (ItemNotFoundException $e) {
		//		}

		$interface->save($announce);

		$this->streamActionService->setActionBool($actor->getId(), $postId, StreamAction::BOOSTED, true);
		$this->signatureService->signObject($actor, $announce);

		$token = $this->activityService->request($announce);

		$this->streamQueueService->cacheStreamByToken($announce->getRequestToken());

		return $announce;
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
		$stream = $this->streamRequest->getStreamByObjectId($postId, Announce::TYPE);

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
		$this->streamService->assignItem($undo, $actor, Stream::TYPE_PUBLIC);
		$undo->setActor($actor);

		$note = $this->streamService->getStreamById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			throw new StreamNotFoundException('Stream is not a Note');
		}

		try {
			$announce = $this->streamRequest->getStreamByObjectId($postId, Announce::TYPE);
			$announce->setActor($actor);

			$undo->setObjectId($announce->getId());
			$undo->addCc($actor->getFollowers());

			$interface = AP::$activityPub->getInterfaceFromType(Announce::TYPE);
			$interface->delete($announce);
			$this->streamRequest->deleteById($announce->getId(), Announce::TYPE);
			$this->signatureService->signObject($actor, $undo);

			$token = $this->activityService->request($undo);
		} catch (ItemUnknownException $e) {
		} catch (StreamNotFoundException $e) {
		}

		$this->streamActionService->setActionBool($actor->getId(), $postId, StreamAction::BOOSTED, false);

		return $undo;
	}
}
