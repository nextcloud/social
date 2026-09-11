<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StatusRevisionsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\StatusRevision;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a status used to say.
 *
 * Two jobs, and the order between them is the whole feature: an edit records
 * the version it replaces before it records the new one, so the first row of
 * any status is the text that was posted. Reading the history back is then a
 * plain ordered select — there is no reconstruction step, because nothing here
 * stores diffs.
 */
class StatusRevisionService {
	public function __construct(
		private StatusRevisionsRequest $revisionsRequest,
		private CacheActorService $cacheActorService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Records an edit, called with the status as it was and as it now is.
	 *
	 * The version being replaced is written only when nothing has been written
	 * for this status yet: on the first edit that version *is* the original,
	 * and on every later edit it is already the last row there is. Writing it
	 * unconditionally would double every intermediate version.
	 *
	 * A failure here is logged and swallowed. The edit itself has already been
	 * stored and federated by the time this runs, and refusing the whole
	 * request afterwards would tell the author their edit did not happen when
	 * it did — a missing revision is the smaller loss, and the only one that
	 * can still be true.
	 */
	public function recordEdit(Stream $before, Stream $after): void {
		try {
			if (!$this->revisionsRequest->hasRevisions($after->getId())) {
				$this->revisionsRequest->save(StatusRevision::fromStream($before));
			}

			$this->revisionsRequest->save(StatusRevision::fromStream($after));
		} catch (Throwable $e) {
			$this->logger->warning('could not record the revision of an edited post', [
				'status' => $after->getId(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Every version of a status, oldest first, as Mastodon StatusEdit
	 * entities.
	 *
	 * A status with no rows is answered with the one version it is in. That is
	 * the honest answer in two cases and there is no third: a status that was
	 * never edited has exactly one version, and a status edited before this
	 * table existed has one version left in the database and no way to know
	 * what the others said. Mastodon's route never returns an empty list — a
	 * client reads `history[0]` to draw "original" — so neither does this one.
	 *
	 * @return StatusRevision[]
	 */
	public function history(Stream $stream): array {
		$revisions = $this->revisionsRequest->getByStreamId($stream->getId());
		if ($revisions === []) {
			$revisions = [StatusRevision::fromStream($stream)];
		}

		$account = $this->author($stream);
		foreach ($revisions as $revision) {
			$revision->setAccount($account);
		}

		return $revisions;
	}

	/**
	 * The status author, as they are now.
	 *
	 * Null rather than a failure when the actor is not cached: a history is
	 * about the text, and refusing to show what a post used to say because its
	 * author's profile has not been fetched would be the wrong trade.
	 */
	private function author(Stream $stream): ?Person {
		try {
			return $this->cacheActorService->getFromId($stream->getAttributedTo());
		} catch (Throwable $e) {
			$this->logger->debug('no cached author for the history of a status', [
				'status' => $stream->getId(),
				'exception' => $e->getMessage(),
			]);

			return null;
		}
	}
}
