<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\StrikesRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\Strike;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * What a moderator has decided about an account, kept after the fact, and the
 * warning that decides nothing.
 *
 * The ladder went from doing nothing straight to taking an account out of the
 * timelines, with no step between and no record of either: `social_moderation`
 * holds one row an account, replaced by the next decision and deleted by a
 * lift, so the third silence in a month looked exactly like the first and
 * whoever lifted the last one took the only evidence it had ever happened.
 *
 * A **warning** is a strike that applied nothing. It is the missing step: the
 * account is told what the problem is, the moderators can see it was told, and
 * nothing about what it can do here changes.
 *
 * The account is told through Nextcloud's notifications, which is the only way
 * this instance can reach anybody — and only reaches a **local** account. A
 * warning about a remote one is a note the moderators keep; telling its owner
 * means telling their instance, and there is no ActivityPub activity that
 * says "your user has been warned".
 *
 * Nothing here removes a strike. A lift says the decision no longer stands,
 * not that it was never taken, and a suspension that took the account's posts
 * and cached actor with it leaves the record of why — which is the difference
 * between a history and the `social_moderation` row it was reconstructed from.
 * `social:reset` and uninstalling empty the table; nothing else does.
 */
class StrikeService {
	public function __construct(
		private StrikesRequest $strikesRequest,
		private ActorsRequest $actorsRequest,
		private INotificationManager $notificationManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Records one, and tells the account if it is one of ours.
	 *
	 * @param string $action Strike::WARNING, or the Moderation level applied
	 * @param string $text what the moderator wrote, shown to the account
	 * @param int $reportId the report it came from, or 0
	 */
	public function record(
		string $actorId, string $action, string $text = '', int $reportId = 0,
	): Strike {
		if (!in_array($action, Strike::ACTIONS, true)) {
			throw new \InvalidArgumentException('unknown strike action: ' . $action);
		}

		$strike = new Strike(
			$actorId, $action, $text, $this->currentUserId(), $reportId, time()
		);
		$this->strikesRequest->save($strike);
		$this->notify($strike);

		return $strike;
	}

	/**
	 * One account's history, newest first.
	 *
	 * @return Strike[]
	 */
	public function history(string $actorId, int $limit = StrikesRequest::HISTORY_LIMIT): array {
		return $this->strikesRequest->getForActor($actorId, $limit);
	}

	/**
	 * How many strikes each of these accounts has, in one query.
	 *
	 * @param string[] $actorIds
	 *
	 * @return array<string, int> actor id => how many, missing when none
	 */
	public function countFor(array $actorIds): array {
		return $this->strikesRequest->countForActors($actorIds);
	}

	/** The moderator who took it, or '' for one taken by a command or a job. */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();

		return $user === null ? '' : $user->getUID();
	}

	/**
	 * Tells a local account what was decided about it.
	 *
	 * A decision nobody was told about is one the account can only discover by
	 * noticing that its posts stopped appearing. Failure costs the
	 * notification and nothing else — the decision has already been recorded
	 * and applied, and a moderator waiting on a notification queue is a
	 * moderator who cannot moderate.
	 */
	private function notify(Strike $strike): void {
		try {
			$actor = $this->actorsRequest->getFromId($strike->getActorId());
		} catch (ActorDoesNotExistException $e) {
			// not one of ours, which is the ordinary case for a strike
			return;
		}

		$userId = $actor->getUserId();
		if ($userId === '') {
			return;
		}

		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('social')
				->setDateTime(new \DateTime('now'))
				->setUser($userId)
				->setObject('strike', (string)$strike->getCreation())
				->setSubject('moderation_warning', [
					'action' => $strike->getAction(),
					'text' => $strike->getText(),
				]);
			$this->notificationManager->notify($notification);
		} catch (\Exception $e) {
			$this->logger->warning('could not tell an account about a strike', [
				'actor' => $strike->getActorId(), 'exception' => $e,
			]);
		}
	}
}
