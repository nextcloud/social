<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * What a Nextcloud user is told about their Social account outside the Social
 * tab, and what they can do with the in-app notifications once told.
 *
 * The app stores its own notifications as stream items — the rows
 * `/api/v1/notifications` serves — and until this existed that was the only
 * place anything was ever said: somebody who was mentioned, followed,
 * favourited or boosted learned about it by opening Social and looking. There
 * is no Web Push here (clients poll), so the Nextcloud notification is the
 * whole fallback: the bell, the mobile app and the mail digest all read it.
 *
 * The emit hangs off the *stored* notification rather than off each of the
 * four places that create one. There is exactly one path that writes a
 * notification row — `SocialAppNotificationInterface::save()` — so hooking it
 * is what keeps the bell and the in-app list from ever disagreeing about what
 * happened: a row that was suppressed, or that was never written because the
 * post was not local, cannot produce a bell either.
 */
class NotificationService {
	/**
	 * Mastodon's notification type for each sub-type that gets a Nextcloud
	 * notification, which is also the subject `Notifier` renders it from.
	 *
	 * `Stream::NOTIFICATION_TYPES` names the same things for the client API.
	 * They are asserted against each other in NotificationServiceTest rather
	 * than shared, because they answer different questions: that map says what
	 * a client may filter on, this one says what a Nextcloud user is told
	 * about, and a type may be added to one before the other.
	 */
	public const SUBJECTS = [
		Mention::TYPE => 'mention',
		Like::TYPE => 'favourite',
		Announce::TYPE => 'reblog',
		Follow::TYPE => 'follow',
		Follow::TYPE_REQUEST => 'follow_request',
		Update::TYPE => 'update',
	];

	/** The `object_type` every notification of this app is filed under. */
	public const OBJECT = 'notification';

	/** How many rows one pass of clear() removes before asking for the next. */
	private const CLEAR_PAGE = ProbeOptions::MAX_LIMIT;

	/**
	 * The most notifications one clear() call will dismiss.
	 *
	 * Each one costs two queries -- a delete and a withdrawal from Nextcloud's
	 * own notification manager -- and the loop was bounded only by how many the
	 * viewer had. An account with a large backlog could therefore spend an
	 * unbounded HTTP request doing it, and time out having cleared some
	 * unknowable fraction anyway. Stopping at a known point and saying so is the
	 * same outcome, minus the timeout: a client that wants the rest calls again.
	 */
	private const CLEAR_MAX = 5000;

	public function __construct(
		private StreamRequest $streamRequest,
		private StreamService $streamService,
		private ActorsRequest $actorsRequest,
		private CacheActorsRequest $cacheActorsRequest,
		private ActorRelationRequest $actorRelationRequest,
		private ActionsRequest $actionsRequest,
		private AccountRelationService $accountRelationService,
		private INotificationManager $notificationManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Tells Nextcloud about a notification row that was just stored.
	 *
	 * Never throws and never lets a failure reach the caller: this is called
	 * from the inbox, and an incoming Like that cannot be announced is still a
	 * Like that has to be accepted.
	 */
	public function onNotification(SocialAppNotification $notification, string $actorId = ''): void {
		try {
			$this->emit($notification, $actorId);
		} catch (Exception $e) {
			$this->logger->warning('could not raise a notification', ['exception' => $e]);
		}
	}

	/**
	 * Mastodon's `update`: the people who boosted a post are told when its
	 * author edits it, because what they passed on is not what it now says.
	 *
	 * Only boosters, which is the notification Mastodon sends and the one this
	 * app's own TODO names. The author is not told about their own edit, and a
	 * remote booster is not told by us — their server notifies them from the
	 * same Update activity.
	 */
	public function onStatusEdited(Stream $post): void {
		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);
		} catch (Exception $e) {
			return;
		}

		$author = $this->accountOf($post->getAttributedTo());

		// part of the id, so a second edit is a second notification rather than
		// a write that collides with the first one and is dropped
		$edit = ($post->getUpdated() !== '') ? $post->getUpdated() : (string)time();

		foreach ($this->actionsRequest->getByObjectId($post->getId()) as $action) {
			if ($action->getType() !== Announce::TYPE
				|| $action->getActorId() === $post->getAttributedTo()
				|| !$this->isLocal($action->getActorId())) {
				continue;
			}

			try {
				/** @var SocialAppNotification $item */
				$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
				$item->setDetailItem('post', $post);
				$item->addDetail('account', $author);
				$item->setAttributedTo($post->getAttributedTo())
					->setSubType(Update::TYPE)
					->setId(
						$post->getId() . '/notification+update/'
						. substr(sha1($action->getActorId() . '/' . $edit), 0, 16)
					)
					->setSummary('{account} edited a post you boosted')
					->setObjectId($post->getId())
					->setTo($action->getActorId())
					->setLocal(true);

				$interface->save($item);
			} catch (Exception $e) {
				$this->logger->warning('could not store an edit notification', ['exception' => $e]);
			}
		}
	}

	/**
	 * Tells the people who asked to hear about an account that it has posted.
	 *
	 * Mastodon's bell on a profile: a subscription separate from following,
	 * written by `POST /accounts/{id}/follow` with `notify` set. Only local
	 * subscribers — a remote one is told by their own server, from the same
	 * post.
	 *
	 * A reply is not one of these. The bell means "tell me when they post",
	 * and a thread somebody else started is not that; Mastodon reads it the
	 * same way.
	 */
	public function onNewStatus(Stream $post): void {
		if ($post->getInReplyTo() !== '') {
			return;
		}

		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);
			$stored = $this->streamRequest->getStreamById($post->getId(), false, ACore::FORMAT_LOCAL);
		} catch (Exception $e) {
			return;
		}

		$author = $this->accountOf($post->getAttributedTo());

		foreach ($this->accountRelationService->subscribersOf($post->getAttributedTo()) as $subscriber) {
			if (!$this->isLocal($subscriber)) {
				continue;
			}

			try {
				/** @var SocialAppNotification $item */
				$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
				$item->setDetailItem('post', $stored);
				$item->addDetail('account', $author);
				$item->setAttributedTo($post->getAttributedTo())
					->setSubType(Stream::SUBTYPE_STATUS)
					->setId($post->getId() . '/notification+status/' . md5($subscriber))
					->setSummary('{account} posted')
					->setObjectId($post->getId())
					->setTo($subscriber)
					->setLocal(true);

				$interface->save($item);
			} catch (Exception $e) {
				$this->logger->warning('could not store a status notification', ['exception' => $e]);
			}
		}
	}

	/**
	 * Tells the people who voted in a poll that it has closed.
	 *
	 * Mastodon tells the author too, which is why the author is not skipped:
	 * somebody who ran a poll wants the result as much as anybody who answered
	 * it.
	 *
	 * @param string[] $voters actor ids
	 */
	public function onPollClosed(Stream $poll, array $voters): void {
		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);
			$stored = $this->streamRequest->getStreamById($poll->getId(), false, ACore::FORMAT_LOCAL);
		} catch (Exception $e) {
			return;
		}

		$author = $this->accountOf($poll->getAttributedTo());

		foreach (array_unique(array_merge($voters, [$poll->getAttributedTo()])) as $reader) {
			if (!$this->isLocal($reader)) {
				continue;
			}

			try {
				/** @var SocialAppNotification $item */
				$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
				$item->setDetailItem('post', $stored);
				$item->addDetail('account', $author);
				$item->setAttributedTo($poll->getAttributedTo())
					->setSubType(Stream::SUBTYPE_POLL)
					->setId($poll->getId() . '/notification+poll/' . md5($reader))
					->setSummary('A poll you voted in has ended')
					->setObjectId($poll->getId())
					->setTo($reader)
					->setLocal(true);

				$interface->save($item);
			} catch (Exception $e) {
				$this->logger->warning('could not store a poll notification', ['exception' => $e]);
			}
		}
	}

	/**
	 * Tells a local account what a moderator decided about it.
	 *
	 * The warning already reached them through Nextcloud's own notifications,
	 * which a Mastodon client cannot see — so somebody moderated through a
	 * client was told nothing a client could show them.
	 */
	public function onModerationWarning(string $actorId, string $action, string $text): void {
		if (!$this->isLocal($actorId)) {
			// telling a remote account means telling its instance, and no
			// activity says "your user has been warned"
			return;
		}

		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);

			/** @var SocialAppNotification $item */
			$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
			$item->addDetail('action', $action);
			$item->addDetail('text', $text);
			$item->setAttributedTo($actorId)
				->setSubType(Stream::SUBTYPE_WARNING)
				->setId($actorId . '/notification+warning/' . md5($action . '/' . $text . '/' . time()))
				->setSummary('A moderator of this server has acted on your account')
				->setTo($actorId)
				->setLocal(true);

			$interface->save($item);
		} catch (Exception $e) {
			$this->logger->warning('could not store a moderation notification', ['exception' => $e]);
		}
	}

	/**
	 * Tells a local account that a block has cut its follows.
	 *
	 * Blocking a domain deletes the follows in both directions, and until this
	 * the accounts that lost them were told nothing — they simply stopped
	 * seeing somebody and had no way of learning why.
	 */
	public function onRelationshipsSevered(string $actorId, string $domain, int $lost): void {
		if ($lost < 1 || !$this->isLocal($actorId)) {
			return;
		}

		try {
			$interface = AP::instance()->getInterfaceFromType(SocialAppNotification::TYPE);

			/** @var SocialAppNotification $item */
			$item = AP::instance()->getItemFromType(SocialAppNotification::TYPE);
			$item->addDetail('target_name', $domain);
			$item->addDetail('relationships_count', (string)$lost);
			$item->setAttributedTo($actorId)
				->setSubType(Stream::SUBTYPE_SEVERED)
				->setId($actorId . '/notification+severed/' . md5($domain))
				->setSummary('Your follows of {target_name} were removed when it was blocked')
				->setTo($actorId)
				->setLocal(true);

			$interface->save($item);
		} catch (Exception $e) {
			$this->logger->warning('could not store a severed-relationships notification', ['exception' => $e]);
		}
	}

	/**
	 * One notification of the viewer's, as `/api/v1/notifications` would have
	 * served it.
	 *
	 * @throws ItemNotFoundException the id names no notification of the
	 *                               viewer's — which is the answer a
	 *                               notification of somebody else's gets too
	 */
	public function get(Person $viewer, int $id): Stream {
		return $this->find($viewer, $id);
	}

	/**
	 * Dismisses one notification.
	 *
	 * The row is deleted, not marked: Mastodon's dismiss is final — the
	 * notification never comes back and no client offers to un-dismiss one —
	 * and a flag would mean every read of the notification timeline carries a
	 * filter for a row that will never be shown again, plus a table to write
	 * it in. Deleting also keeps the two halves in step, since the list is
	 * built straight off these rows: there is no second place where a
	 * dismissed notification could still exist.
	 *
	 * The post the notification is about is untouched. So is the Like or
	 * Announce behind it, so counters do not move because somebody cleared
	 * their bell.
	 *
	 * @throws ItemNotFoundException
	 */
	public function dismiss(Person $viewer, int $id): void {
		$notification = $this->find($viewer, $id);

		$this->streamRequest->deleteById($notification->getId(), SocialAppNotification::TYPE);
		$this->forget($viewer, $notification->getId());
	}

	/**
	 * Dismisses every notification the viewer has, and answers how many that
	 * was.
	 *
	 * Paged, with a cursor that only ever moves down: the rows are deleted as
	 * they are read, so re-asking for the first page would work too, but a
	 * delete that silently matched nothing would then loop forever.
	 *
	 * Stops at CLEAR_MAX and leaves the rest for the next call.
	 */
	public function clear(Person $viewer): int {
		$cleared = 0;
		$maxId = 0;

		while ($cleared < self::CLEAR_MAX) {
			$page = $this->page($viewer, min(self::CLEAR_PAGE, self::CLEAR_MAX - $cleared), $maxId);
			if ($page === []) {
				return $cleared;
			}

			foreach ($page as $notification) {
				$this->streamRequest->deleteById($notification->getId(), SocialAppNotification::TYPE);
				$this->forget($viewer, $notification->getId());
				$maxId = $notification->getNid();
				$cleared++;
			}
		}

		$this->logger->info(
			'stopped clearing notifications at the per-call ceiling of ' . self::CLEAR_MAX
			. '; the account has more left to dismiss',
			['actor' => $viewer->getId()]
		);

		return $cleared;
	}

	/**
	 * @throws Exception
	 */
	private function emit(SocialAppNotification $notification, string $actorId): void {
		$subject = self::SUBJECTS[$notification->getSubType()] ?? '';
		if ($subject === '') {
			// nothing a client can name and nothing this app can word; a bell
			// for it would say only that "something happened"
			return;
		}

		$recipientId = $notification->getTo();
		$actorId = ($actorId !== '') ? $actorId : $this->actorOf($notification);
		if ($recipientId === '' || $actorId === '' || $recipientId === $actorId) {
			// somebody boosting their own post, or replying to themselves with
			// their own handle in it, is not news to them
			return;
		}

		try {
			$recipient = $this->actorsRequest->getFromId($recipientId);
		} catch (Exception $e) {
			return; // not an account of this server: there is no user to tell
		}

		if ($recipient->getUserId() === '' || $this->isSuppressed($recipientId, $actorId)) {
			return;
		}

		$actor = $this->cachedActor($actorId);
		$link = in_array($subject, ['follow', 'follow_request'], true)
			? $actorId
			: $notification->getObjectId();

		$raised = $this->notificationManager->createNotification();
		$raised->setApp('social')
			->setDateTime(new DateTime('now'))
			->setUser($recipient->getUserId())
			->setObject(self::OBJECT, $this->objectId($notification->getId()))
			->setSubject($subject, [
				'account' => ($actor === null) ? $actorId : $this->labelOf($actor),
				'link' => $link,
				'avatar' => ($actor === null) ? '' : $actor->getAvatar(),
			]);

		$this->notificationManager->notify($raised);
	}

	/**
	 * Who acted.
	 *
	 * `attributedTo` for all but a mention: a mention row is attributed to the
	 * account being told, not to the author who wrote the post, so the author
	 * is read off the post the row points at — from the copy the caller
	 * already has where there is one.
	 */
	private function actorOf(SocialAppNotification $notification): string {
		if ($notification->getSubType() !== Mention::TYPE) {
			return ($notification->getAttributedTo() !== '')
				? $notification->getAttributedTo() : $notification->getActorId();
		}

		$post = $notification->getDetailsAll()['post'] ?? null;
		if ($post instanceof Stream) {
			return $post->getAttributedTo();
		}

		try {
			return $this->streamRequest->getStreamById($notification->getObjectId())->getAttributedTo();
		} catch (Exception $e) {
			return '';
		}
	}

	/**
	 * Nothing is raised for an actor the recipient has blocked, who has
	 * blocked the recipient, or whom the recipient muted with notifications
	 * hidden — the rule the stored row is written under, applied to the actor
	 * who acted rather than to the row's `attributedTo`.
	 *
	 * A mute that has run out is not a mute: the row stays in place and stops
	 * applying, which is how a timed mute ends without anything deleting it.
	 */
	private function isSuppressed(string $recipientId, string $actorId): bool {
		foreach ($this->actorRelationRequest->getBetween($recipientId, $actorId) as $relation) {
			if ($relation->getType() === ActorRelation::TYPE_BLOCK
				|| $relation->getType() === ActorRelation::TYPE_BLOCKED_BY) {
				return true;
			}

			if ($relation->getType() === ActorRelation::TYPE_MUTE
				&& $relation->isNotifications()
				&& !$this->accountRelationService->isMuteExpired($recipientId, $actorId)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The notification's `object_id`, which the notifications table caps at 64
	 * characters — an ActivityPub id is routinely longer than that. Derived,
	 * so dismissing the in-app row can clear the same Nextcloud notification
	 * without anything having stored the pairing.
	 */
	private function objectId(string $id): string {
		return sha1($id);
	}

	/** Takes the bell entry down with the notification it announced. */
	private function forget(Person $viewer, string $id): void {
		$userId = $viewer->getUserId();
		if ($userId === '') {
			try {
				$userId = $this->actorsRequest->getFromId($viewer->getId())->getUserId();
			} catch (Exception $e) {
				return;
			}
		}

		try {
			$raised = $this->notificationManager->createNotification();
			$raised->setApp('social')
				->setUser($userId)
				->setObject(self::OBJECT, $this->objectId($id));

			$this->notificationManager->markProcessed($raised);
		} catch (Exception $e) {
			$this->logger->warning('could not withdraw a notification', ['exception' => $e]);
		}
	}

	/**
	 * The viewer's notification with this id.
	 *
	 * Read through the same query the timeline is built from, so a
	 * notification that is not the viewer's is not found rather than refused,
	 * and one whose sub-type has no Mastodon name — which the timeline leaves
	 * out, since a client cannot decode it — is not found either.
	 *
	 * @throws ItemNotFoundException
	 */
	private function find(Person $viewer, int $id): Stream {
		if ($id > 0) {
			foreach ($this->page($viewer, 1, $id + 1, $id - 1) as $notification) {
				if ($notification->getNid() === $id
					&& Stream::notificationTypeOfSubType($notification->getSubType()) !== '') {
					return $notification;
				}
			}
		}

		throw new ItemNotFoundException('Record not found');
	}

	/**
	 * A page of the viewer's notifications, newest first.
	 *
	 * @return Stream[]
	 */
	private function page(Person $viewer, int $limit, int $maxId = 0, int $minId = 0): array {
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_LOCAL);
		$options->setProbe(ProbeOptions::NOTIFICATIONS)
			->setLimit($limit)
			->setMaxId($maxId)
			->setMinId($minId);

		$this->streamService->setViewer($viewer);

		return $this->streamService->getTimeline($options);
	}

	private function cachedActor(string $actorId): ?Person {
		try {
			// the local cache only: an inbox request must not go out to
			// another server to find out what to call somebody
			return $this->cacheActorsRequest->getFromId($actorId);
		} catch (Exception $e) {
			return null;
		}
	}

	/** What to call an account in a sentence. */
	private function labelOf(Person $actor): string {
		foreach ([$actor->getName(), $actor->getAccount(), $actor->getPreferredUsername()] as $label) {
			if ($label !== '') {
				return $label;
			}
		}

		return $actor->getId();
	}

	private function accountOf(string $actorId): string {
		$actor = $this->cachedActor($actorId);

		return ($actor === null) ? $actorId : $this->labelOf($actor);
	}

	private function isLocal(string $actorId): bool {
		try {
			$this->actorsRequest->getFromId($actorId);

			return true;
		} catch (Exception $e) {
			return false;
		}
	}
}
