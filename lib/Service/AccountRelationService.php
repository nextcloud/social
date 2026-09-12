<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\AccountNotesRequest;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\MuteExpiryRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Relationship;

/**
 * The three things one account keeps about another beside a follow, a block or
 * a mute: a private note, an endorsement, and the moment a mute runs out.
 *
 * None of them is federated and none of them is visible to the account they are
 * about — an endorsement is published on the viewer's own profile, but the row
 * here is only ever read for the account that wrote it.
 */
class AccountRelationService {
	/**
	 * An endorsement is a row in `social_actor_relation`, the table that
	 * already holds blocks and mutes, because it is exactly that shape: one
	 * actor, one other actor, one word, no payload. It gets that table's unique
	 * index — endorsing twice is endorsing once — and its account-deletion
	 * cleanup for nothing. The timelines ignore it: they hide the types they
	 * name, and this is not one of them.
	 */
	public const TYPE_ENDORSE = 'endorse';

	/**
	 * The bell on a profile: "tell me when this account posts".
	 *
	 * The same shape as an endorsement, and in the same table for the same
	 * reasons. Separate from the follow, because Mastodon's is: somebody may
	 * follow an account without wanting to be told every time it writes, and
	 * `POST /accounts/{id}/follow` carries `notify` for exactly that.
	 */
	public const TYPE_NOTIFY = 'notify';

	/** What Mastodon caps a note at. */
	public const MAX_NOTE = 2000;

	/**
	 * The longest mute this stores. Nothing refuses a longer one — it is
	 * written as this, and a mute that ends in ten years is a permanent mute in
	 * every way a user can tell, while an unbounded `time() + duration`
	 * overflows the column it is written to.
	 */
	public const MAX_DURATION = 315360000;

	public function __construct(
		private AccountNotesRequest $accountNotesRequest,
		private ActorRelationRequest $actorRelationRequest,
		private FollowsRequest $followsRequest,
		private MuteExpiryRequest $muteExpiryRequest,
		private DomainBlockService $domainBlockService,
		private RelationshipService $relationshipService,
	) {
	}

	/**
	 * The note as it is stored: what was typed, without the space around it and
	 * no longer than Mastodon keeps one. An empty note is not a row — clearing
	 * a note and never having written one are the same state, and Mastodon
	 * clears one by sending an empty string.
	 */
	public static function normaliseNote(string $note): string {
		$note = trim($note);

		// characters, not bytes: cutting mid-character would store a broken one
		return (mb_strlen($note) > self::MAX_NOTE) ? mb_substr($note, 0, self::MAX_NOTE) : $note;
	}

	/**
	 * When a mute asked for now with `$duration` seconds runs out; 0 for a mute
	 * that does not.
	 *
	 * A duration of 0 is Mastodon's "until I say otherwise". A negative one is
	 * a mute that has already expired, which is not a mute at all: it is read
	 * as permanent, because a client that sent it meant to mute.
	 */
	public static function expiryOf(int $duration, ?int $now = null): int {
		if ($duration <= 0) {
			return 0;
		}

		return ($now ?? time()) + min($duration, self::MAX_DURATION);
	}

	public function setNote(Person $viewer, Person $target, string $note): string {
		$note = self::normaliseNote($note);
		if ($note === '') {
			$this->accountNotesRequest->delete($viewer->getId(), $target->getId());

			return '';
		}

		$this->accountNotesRequest->save($viewer->getId(), $target->getId(), $note);

		return $note;
	}

	public function getNote(string $viewerId, string $actorId): string {
		return $this->accountNotesRequest->getNote($viewerId, $actorId);
	}

	/**
	 * @throws InvalidResourceException endorsing yourself, or endorsing an
	 *                                  account you do not follow — Mastodon's
	 *                                  "Account must be followed", because an
	 *                                  endorsement is published as part of
	 *                                  saying who you follow
	 */
	public function endorse(Person $viewer, Person $target): void {
		if ($viewer->getId() === $target->getId()) {
			throw new InvalidResourceException('cannot feature your own account');
		}

		try {
			$follow = $this->followsRequest->getByPersons($viewer->getId(), $target->getId());
		} catch (FollowNotFoundException $e) {
			throw new InvalidResourceException('Account must be followed');
		}

		if (!$follow->isAccepted()) {
			// a follow the other side has not answered is not a follow yet, and
			// featuring somebody who may still refuse would publish a claim the
			// viewer has not earned
			throw new InvalidResourceException('Account must be followed');
		}

		$this->actorRelationRequest->save($viewer->getId(), $target->getId(), self::TYPE_ENDORSE);
	}

	/** Unfeaturing an account that was not featured is not an error. */
	/**
	 * Turns the bell on a profile on or off.
	 *
	 * Writing it needs no follow: Mastodon sends `notify` *with* the follow, so
	 * demanding an accepted follow first would refuse the one call that ever
	 * sets it. What it subscribes to is the account posting, which is public
	 * of anybody whose posts the subscriber can already see.
	 */
	public function setNotify(Person $viewer, Person $target, bool $notify): void {
		if ($viewer->getId() === $target->getId()) {
			// being told about your own posts is a notification nobody wants
			return;
		}

		if ($notify) {
			$this->actorRelationRequest->save($viewer->getId(), $target->getId(), self::TYPE_NOTIFY);

			return;
		}

		$this->actorRelationRequest->delete($viewer->getId(), $target->getId(), self::TYPE_NOTIFY);
	}

	/** Whether the viewer has asked to be told when this account posts. */
	public function isNotified(string $viewerId, string $targetId): bool {
		return $this->actorRelationRequest->exists($viewerId, $targetId, self::TYPE_NOTIFY);
	}

	/**
	 * Who asked to be told when this account posts.
	 *
	 * @return string[] actor ids
	 */
	public function subscribersOf(string $targetId): array {
		return $this->actorRelationRequest->getLocalByObject($targetId, self::TYPE_NOTIFY);
	}

	public function unendorse(Person $viewer, Person $target): void {
		$this->actorRelationRequest->delete($viewer->getId(), $target->getId(), self::TYPE_ENDORSE);
	}

	public function isEndorsing(string $viewerId, string $actorId): bool {
		return $this->actorRelationRequest->exists($viewerId, $actorId, self::TYPE_ENDORSE);
	}

	/**
	 * The accounts the viewer features on their profile, newest first.
	 *
	 * @return Person[]
	 */
	public function getEndorsed(Person $viewer, int $limit = 40): array {
		return $this->relationshipService->getRelated($viewer, self::TYPE_ENDORSE, $limit);
	}

	/**
	 * Records when a mute taken now runs out. A duration of 0 removes the
	 * expiry rather than writing one, so re-muting an account permanently ends
	 * a mute that used to be timed.
	 */
	public function setMuteExpiry(Person $viewer, Person $target, int $duration, ?int $now = null): int {
		$expiresAt = self::expiryOf($duration, $now);
		if ($expiresAt === 0) {
			$this->muteExpiryRequest->delete($viewer->getId(), $target->getId());

			return 0;
		}

		$this->muteExpiryRequest->save($viewer->getId(), $target->getId(), $expiresAt);

		return $expiresAt;
	}

	/** Unmuting takes the expiry with it: the mute it belonged to is gone. */
	public function clearMuteExpiry(Person $viewer, Person $target): void {
		$this->muteExpiryRequest->delete($viewer->getId(), $target->getId());
	}

	/**
	 * Whether the mute the viewer holds over this account has run out.
	 *
	 * Asked of the read rather than of a cron job: a mute stops applying the
	 * second it expires, on an instance whose background jobs never run as much
	 * as on one where they do.
	 */
	public function isMuteExpired(string $viewerId, string $actorId, ?int $now = null): bool {
		$expiresAt = $this->muteExpiryRequest->getExpiry($viewerId, $actorId);

		return $expiresAt !== 0 && $expiresAt <= ($now ?? time());
	}

	/**
	 * The accounts of a mutes listing, without the ones whose mute has run out.
	 *
	 * One query for the whole page — the listing is a page of accounts, not one
	 * account asked about repeatedly.
	 *
	 * @param Person[] $accounts
	 *
	 * @return Person[]
	 */
	public function withoutExpiredMutes(Person $viewer, array $accounts, ?int $now = null): array {
		if ($accounts === []) {
			return [];
		}

		$expiries = $this->muteExpiryRequest->getExpiries(
			$viewer->getId(), array_map(static fn (Person $account): string => $account->getId(), $accounts)
		);
		$now ??= time();

		return array_values(
			array_filter($accounts, static function (Person $account) use ($expiries, $now): bool {
				$expiresAt = $expiries[$account->getId()] ?? 0;

				return $expiresAt === 0 || $expiresAt > $now;
			})
		);
	}

	/**
	 * The three fields of a Relationship that no other lookup fills in, and the
	 * one it fills in too generously.
	 *
	 * `domain_blocking`, `note` and `endorsed` are each a fact about the pair
	 * the relationship is being built for, and `muting` is read off a row that
	 * may have run out — the mute is left in place and stops being reported,
	 * which is what makes an expiry work without anything deleting it.
	 *
	 * `endorsed` is not read here: the relation row it comes from is already in
	 * hand where this is called, and asking for it again would be a query for
	 * something the caller just read.
	 */
	/**
	 * `decorate()` for a whole page, in two queries instead of two per account.
	 *
	 * The single-account version is a lookup of the note and a lookup of the
	 * mute's expiry; asked about forty accounts it was eighty round trips.
	 * `domain_blocking` is not among them: it reads a per-viewer list that is
	 * already memoised, so the first account pays for it and the rest do not.
	 *
	 * @param array<string, Relationship> $relationships keyed by actor id
	 */
	public function decorateMany(array $relationships, string $viewerId, ?int $now = null): void {
		if ($relationships === []) {
			return;
		}

		$actorIds = array_keys($relationships);
		$notes = $this->accountNotesRequest->getNotes($viewerId, $actorIds);
		$expiries = $this->muteExpiryRequest->getExpiries($viewerId, $actorIds);
		$now ??= time();

		foreach ($relationships as $actorId => $relationship) {
			$relationship->setDomainBlocking($this->domainBlockService->isBlocking($viewerId, $actorId));
			$relationship->setNote($notes[$actorId] ?? '');

			$expiresAt = $expiries[$actorId] ?? 0;
			if ($relationship->isMuting() && $expiresAt !== 0 && $expiresAt <= $now) {
				$relationship->setMuting(false)->setMutingNotifications(false);
			}
		}
	}

	public function decorate(
		Relationship $relationship, string $viewerId, string $actorId, ?int $now = null,
	): Relationship {
		$relationship->setDomainBlocking($this->domainBlockService->isBlocking($viewerId, $actorId));
		$relationship->setNote($this->getNote($viewerId, $actorId));

		if ($relationship->isMuting() && $this->isMuteExpired($viewerId, $actorId, $now)) {
			$relationship->setMuting(false)
				->setMutingNotifications(false);
		}

		return $relationship;
	}
}
