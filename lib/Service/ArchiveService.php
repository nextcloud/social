<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * Putting one of your own posts away, and getting it back.
 *
 * Deleting was the only thing this app offered somebody who no longer wanted a
 * post on their profile, and it is a bad answer to a common question: a
 * photograph from four years ago is not something to destroy because it has
 * stopped belonging at the top of a profile.
 *
 * **Nothing federates.** An archived post is still on every server that
 * received it, and taking it back from them is what `Delete` is for — a
 * different decision, with a different button, that cannot be undone. What
 * archiving says is "not on my profile *here* any more", and it is
 * reversible, which is the whole point of it.
 *
 * The audience is unchanged too. A public post that has been archived is not
 * thereby private: somebody who kept the link still opens it, and anybody who
 * boosted it still shows it. Archiving hides a post from the lists this
 * server builds, and a post is not a secret because it is out of a list — a
 * post that must stop being readable is one to delete.
 */
class ArchiveService {
	/** What one page of somebody's archive holds. */
	public const PAGE = 50;

	public function __construct(
		private StreamRequest $streamRequest,
	) {
	}

	/**
	 * @throws ItemNotFoundException no such post *of this account*, which is
	 *                               also the answer for somebody else's and
	 *                               for one this server did not write
	 */
	public function archive(Person $actor, int $nid): void {
		if (!$this->streamRequest->setArchived($nid, $actor->getId(), true)) {
			throw new ItemNotFoundException('no post of yours with that id');
		}
	}

	/** @throws ItemNotFoundException */
	public function restore(Person $actor, int $nid): void {
		if (!$this->streamRequest->setArchived($nid, $actor->getId(), false)) {
			throw new ItemNotFoundException('no post of yours with that id');
		}
	}

	/**
	 * The account's own archive, newest first.
	 *
	 * @return Stream[]
	 */
	public function forActor(Person $actor, int $limit = self::PAGE, int $maxId = 0): array {
		return $this->streamRequest->getArchivedByActor(
			$actor->getId(), max(1, min($limit, self::PAGE)), $maxId
		);
	}

	public function countFor(Person $actor): int {
		return $this->streamRequest->countArchivedByActor($actor->getId());
	}
}
