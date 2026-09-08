<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamQueue;
use OCA\Social\Service\ForwardService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PushService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Tools\Traits\TArrayTools;

class NoteInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	use TArrayTools;

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private PollService $pollService;
	private PushService $pushService;

	public function __construct(
		StreamRequest $streamRequest,
		CacheActorsRequest $cacheActorsRequest,
		PollService $pollService,
		PushService $pushService,
		private StreamQueueService $streamQueueService,
		private LinkPreviewService $linkPreviewService,
		private ForwardService $forwardService,
	) {
		$this->streamRequest = $streamRequest;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->pollService = $pollService;
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

			// asked before the save, because afterwards every delivery of this
			// activity looks alike and a re-delivery would be forwarded again
			$known = $this->isKnown($item->getId());
			$this->save($item);

			if (!$known) {
				$this->forwardService->forwardReply($activity, $item);
			}
		}

		if ($activity->getType() === Delete::TYPE) {
			$activity->checkOrigin($item->getId());
			$this->delete($item);
		}

		if ($activity->getType() === Update::TYPE) {
			$activity->checkOrigin($item->getId());
			$activity->checkOrigin($item->getAttributedTo());
			$item->setActivityId($activity->getId());
			$this->streamRequest->update($item);
		}
	}

	private function isKnown(string $id): bool {
		try {
			$this->streamRequest->getStreamById($id);

			return true;
		} catch (StreamNotFoundException $e) {
			return false;
		}
	}

	public function save(ACore $item): void {
		/** @var Note $note */
		$note = $item;
		$this->checkAuthorship($note);

		// a bare note replying to one of our polls with an option as its name
		// is a vote: counted, never stored as a timeline item
		if ($this->pollService->handleIncomingVote($note)) {
			return;
		}

		try {
			$this->streamRequest->getStreamById($note->getId());
		} catch (StreamNotFoundException $e) {
			if ($note->getVisibility() === '') {
				$note->setVisibility($this->estimateVisibility($note));
			}
			$this->streamRequest->save($note);
			$this->updateDetails($note);
			$this->generateNotification($note);
			$this->pushService->onNewStream($note->getId());
			$this->queueLinkPreview($note);
		}
	}

	/**
	 * A post that links somewhere gets its preview read by a background job:
	 * reading the page here would hold up the inbox for as long as a stranger's
	 * web server feels like taking.
	 */
	private function queueLinkPreview(Note $note): void {
		if ($this->linkPreviewService->extractUrl($note->getContent()) === '') {
			return;
		}

		$this->streamQueueService->generateStreamQueue(
			$note->getRequestToken(), StreamQueue::TYPE_LINK_PREVIEW, $note->getId()
		);
	}

	/**
	 * A note lives on its author's instance, so `attributedTo` must share the host of
	 * the note's own id. This is the invariant every path into storage relies on: the
	 * Create path also matches both against the request origin, but the fetch-and-store
	 * paths (an announced object being cached, an outbox being synced) have no request
	 * to compare against — without this check, a document served by one instance could
	 * claim an author on another and be stored as that author's post.
	 *
	 * @throws InvalidOriginException
	 */
	private function checkAuthorship(Note $note): void {
		$noteHost = parse_url($note->getId(), PHP_URL_HOST);
		$authorHost = parse_url($note->getAttributedTo(), PHP_URL_HOST);

		if (!is_string($noteHost) || $noteHost === ''
			|| !is_string($authorHost)
			|| strtolower($noteHost) !== strtolower($authorHost)) {
			throw new InvalidOriginException(
				'NoteInterface::checkAuthorship - id: ' . $note->getId()
				. ' - attributedTo: ' . $note->getAttributedTo()
			);
		}
	}

	public function delete(ACore $item): void {
		/** @var Note $item */
		$this->streamRequest->deleteById($item->getId(), Note::TYPE);
		$this->linkPreviewService->deleteCard($item->getId());
	}

	public function updateDetails(Note $stream): void {
		if ($stream->getInReplyTo() === '') {
			return;
		}

		try {
			$orig = $this->streamRequest->getStreamById($stream->getInReplyTo());
			$remoteReplies = $orig->getDetailInt('remote_replies');
			$localReplies = $this->streamRequest->countRepliesTo($stream->getInReplyTo());
			$orig->setDetailInt('replies', $remoteReplies + $localReplies);

			$this->streamRequest->updateDetails($orig);
		} catch (StreamNotFoundException $e) {
		}
	}

	/**
	 * A remote note carries no Mastodon-style visibility field; estimate it
	 * from its addressing the way Mastodon serialises it: as:Public in `to`
	 * is public, as:Public in `cc` is unlisted, the author's followers
	 * collection makes it followers-only, anything else is a direct message.
	 */
	private function estimateVisibility(Note $note): string {
		if (in_array(ACore::CONTEXT_PUBLIC, $note->getToAll(), true)) {
			return Stream::TYPE_PUBLIC;
		}
		if (in_array(ACore::CONTEXT_PUBLIC, $note->getCcArray(), true)) {
			return Stream::TYPE_UNLISTED;
		}

		try {
			$author = $this->cacheActorsRequest->getFromId($note->getAttributedTo());
			if ($author->getFollowers() !== ''
				&& in_array($author->getFollowers(), array_merge($note->getToAll(), $note->getCcArray()), true)) {
				return Stream::TYPE_FOLLOWERS;
			}
		} catch (CacheActorDoesNotExistException $e) {
		}

		return Stream::TYPE_DIRECT;
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
