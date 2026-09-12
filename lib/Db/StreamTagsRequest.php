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
		if ($stream->getType() !== Note::TYPE) {
			return;
		}

		/** @var Note $stream */
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

	public function emptyStreamTags(): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_TAGS);

		$qb->executeStatement();
	}
}
