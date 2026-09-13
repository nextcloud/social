<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Suggestion;

/**
 * Accounts to follow: `GET /api/v2/suggestions`.
 *
 * Derived from three things the app already has and from nothing else. The
 * first is the follow graph: the accounts followed by the accounts the viewer
 * follows, ordered by how many of them do. The second is this Nextcloud's own
 * profiles: the handles the people here wrote in their `fediverse` field, which
 * is the one signal about who to follow that a graph the viewer is not yet part
 * of cannot give -- see `ColleagueService`. The third, for a viewer left with
 * empty slots after both, is the local accounts that opted in to the directory,
 * most recently active first.
 *
 * There is no scoring model and deliberately no attempt at one. Both halves
 * are facts that can be counted, and a suggestion list that cannot explain
 * itself is worse than a short one.
 *
 * Four kinds of account are never suggested, and the exclusions are applied
 * after both halves are gathered rather than inside either: somebody the
 * viewer follows, somebody they have blocked or muted, somebody who has
 * blocked them, and the viewer themselves. Missing one of these is the failure
 * mode that matters — a suggestion to follow an account you blocked is the
 * instance overruling the one decision the user made about it.
 */
class SuggestionService {
	/** What Mastodon defaults and caps the suggestion list at. */
	public const LIMIT = 40;
	public const MAX_LIMIT = 80;

	public function __construct(
		private DiscoveryRequest $discoveryRequest,
		private DirectoryService $directoryService,
		private ColleagueService $colleagueService,
		private ActorsRequest $actorsRequest,
	) {
	}

	/**
	 * @return Suggestion[]
	 */
	public function suggestions(string $viewerId, int $limit): array {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$excluded = $this->excluded($viewerId);

		$friends = $this->keep(
			$this->discoveryRequest->friendsOfFriendsPrims($viewerId, $limit * 2), $excluded, $limit
		);

		// the graph first, then the people who share this Nextcloud, then
		// whoever is around: each is a better answer than the next, so each
		// only fills the slots the one before it left empty
		$excluded += array_fill_keys($friends, true);
		$colleagues = $this->keep(
			$this->colleaguePrims($viewerId), $excluded, $limit - count($friends)
		);

		$excluded += array_fill_keys($colleagues, true);
		$active = $this->keep(
			$this->discoveryRequest->activeLocalPrims($limit * 2),
			$excluded,
			$limit - count($friends) - count($colleagues)
		);

		$suggestions = [];
		$sources = [
			Suggestion::SOURCE_FRIENDS => $friends,
			Suggestion::SOURCE_COLLEAGUES => $colleagues,
			Suggestion::SOURCE_ACTIVE => $active,
		];
		foreach ($sources as $source => $prims) {
			$prims = $this->directoryService->withoutModerated($prims);
			foreach ($this->discoveryRequest->actorsByPrims($prims) as $actor) {
				$suggestions[] = new Suggestion($actor, $source);
			}
		}

		return $suggestions;
	}

	/**
	 * The accounts named on this Nextcloud's profiles, as prims, in the order
	 * the profiles were read.
	 *
	 * The viewer's own profile is left out by their user id, which the actor
	 * carries; a viewer with no local actor row -- a bearer token for an account
	 * that was deleted, say -- simply has no colleagues to leave out.
	 *
	 * @return string[]
	 */
	private function colleaguePrims(string $viewerId): array {
		try {
			$exceptUserId = $this->actorsRequest->getFromId($viewerId)->getUserId();
		} catch (Exception $e) {
			$exceptUserId = '';
		}

		return array_map(
			static fn (Person $account): string => md5($account->getId()),
			$this->colleagueService->accounts($exceptUserId)
		);
	}

	/**
	 * Every account that may not be suggested, as a set of prims.
	 *
	 * The viewer is in it: an account cannot follow itself, and Mastodon's own
	 * list never contains the asking account.
	 *
	 * @return array<string, bool>
	 */
	private function excluded(string $viewerId): array {
		$prims = array_merge(
			[md5($viewerId)],
			$this->discoveryRequest->followedPrims($viewerId),
			$this->discoveryRequest->relatedPrims($viewerId, [
				ActorRelation::TYPE_BLOCK,
				ActorRelation::TYPE_MUTE,
				ActorRelation::TYPE_BLOCKED_BY,
			])
		);

		return array_fill_keys($prims, true);
	}

	/**
	 * The first `$limit` of `$prims` that are not excluded and not already
	 * taken, in the order they arrived.
	 *
	 * @param string[] $prims
	 * @param array<string, bool> $excluded
	 *
	 * @return string[]
	 */
	private function keep(array $prims, array $excluded, int $limit): array {
		if ($limit < 1) {
			return [];
		}

		$kept = [];
		foreach ($prims as $prim) {
			if (isset($excluded[$prim]) || isset($kept[$prim])) {
				continue;
			}

			$kept[$prim] = true;
			if (count($kept) >= $limit) {
				break;
			}
		}

		return array_keys($kept);
	}
}
