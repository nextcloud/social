<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\DB\Exception as DBException;

/**
 * Class StreamTagsRequest
 *
 * @package OCA\Social\Db
 */
class StreamTagsRequest extends StreamTagsRequestBuilder {
	use TStringTools;

	/**
	 * A post can carry the same hashtag twice, and the unique index refuses the
	 * second row. That used to be caught and logged, which was enough while
	 * these rows were written on their own. They are now written inside the
	 * transaction that stores the post, and PostgreSQL aborts a transaction on
	 * any failed statement -- so the swallowed duplicate took the commit, and
	 * the post, with it. The database is asked to skip the row instead.
	 */
	public function generateStreamTags(Stream $stream): void {
		// a poll is a `Question`, which extends `Note` and carries hashtags
		// like any other post; the type name alone left every poll out of
		// every hashtag timeline
		if (!$stream instanceof Note) {
			return;
		}

		$streamId = $this->getQueryBuilder()->prim($stream->getId());
		foreach ($stream->getHashTags() as $hashtag) {
			try {
				$this->dbConnection->insertIgnoreConflict(
					self::TABLE_STREAM_TAGS,
					[
						'stream_id' => $streamId,
						'hashtag' => $hashtag,
					]
				);
			} catch (DBException $e) {
				$this->logger->error('could not store a hashtag of a stream', [
					'streamId' => $stream->getId(),
					'hashtag' => $hashtag,
					'exception' => $e,
				]);

				throw $e;
			}
		}
	}

	/**
	 * The tag rows of an edited post, rewritten to what it now says.
	 *
	 * An edit is not an insert: a hashtag taken out of the text has a row
	 * that nothing else will ever remove, so the post stays in that tag's
	 * timeline for good, and a hashtag written into the text has no row at
	 * all. Both are one statement group with the update itself — see
	 * `StreamRequest::update()` — so the rows and the `hashtags` column can
	 * never disagree.
	 */
	public function replaceStreamTags(Stream $stream): void {
		if (!$stream instanceof Note) {
			return;
		}

		$this->deleteStreamTags($stream->getId());
		$this->generateStreamTags($stream);
	}

	/** Removes every tag row of one post. */
	public function deleteStreamTags(string $streamId): void {
		$prim = $this->getQueryBuilder()->prim($streamId);
		if ($prim === '') {
			return;
		}

		$qb = $this->getStreamTagsDeleteSql();
		$qb->where($qb->expr()->eq('stream_id', $qb->createNamedParameter($prim)));
		$qb->executeStatement();
	}

	public function emptyStreamTags(): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_TAGS);

		$qb->executeStatement();
	}
}
