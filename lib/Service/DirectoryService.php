<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * The local profile directory: `GET /api/v1/directory`.
 *
 * Opt-in, and that is the whole of the access rule. `discoverable` has been
 * stored on `social_actor` and federated on the actor since
 * `Version1000Date20260911000002`, and until this route existed nothing read
 * it — the flag a user could turn off had no effect anywhere, because there
 * was no listing for it to keep them out of. The flag is applied as a
 * predicate of the deciding query rather than as a filter over its result, so
 * an account that did not opt in never occupies a slot in the page.
 *
 * Remote accounts are never listed. Mastodon's `local` parameter is accepted
 * and makes no difference: `discoverable` is only meaningful for accounts this
 * instance holds the profile of, and listing a cached remote actor would be
 * this instance publishing a directory of somebody else's users.
 */
class DirectoryService {
	/** What Mastodon defaults and caps a page of the directory at. */
	public const LIMIT = 40;
	public const MAX_LIMIT = 80;

	public function __construct(
		private DiscoveryRequest $discoveryRequest,
		private ModerationService $moderationService,
	) {
	}

	/**
	 * A page of the directory.
	 *
	 * @param string $order `active` (most recently posted first, Mastodon's
	 *                      default) or `new` (newest account first); anything
	 *                      else is read as `active`
	 *
	 * @return Person[]
	 */
	public function page(string $order, int $limit, int $offset): array {
		$order = ($order === DiscoveryRequest::ORDER_NEW)
			? DiscoveryRequest::ORDER_NEW
			: DiscoveryRequest::ORDER_ACTIVE;

		$prims = $this->discoveryRequest->directoryPrims(
			$order, max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset)
		);

		return $this->discoveryRequest->actorsByPrims($this->withoutModerated($prims));
	}

	/**
	 * Drops every account a moderator has made a decision about.
	 *
	 * Any decision, not only a suspension. Silencing means the instance stops
	 * putting the account in front of people who did not ask for it, and a
	 * directory is the instance putting an account in front of exactly those
	 * people — so a silenced account that still appeared here would have been
	 * removed from the public timeline and left in the shop window.
	 *
	 * @param string[] $prims
	 *
	 * @return string[]
	 */
	public function withoutModerated(array $prims): array {
		if ($prims === []) {
			return [];
		}

		$moderated = [];
		foreach ($this->moderationService->decisions() as $decision) {
			$moderated[md5($decision->getActorId())] = true;
		}

		if ($moderated === []) {
			return $prims;
		}

		return array_values(
			array_filter($prims, static fn (string $prim): bool => !isset($moderated[$prim]))
		);
	}
}
