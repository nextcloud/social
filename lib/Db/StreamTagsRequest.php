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
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Class StreamTagsRequest
 *
 * @package OCA\Social\Db
 */
class StreamTagsRequest extends StreamTagsRequestBuilder {
	use TStringTools;

	public function generateStreamTags(Stream $stream): void {
		if ($stream->getType() !== Note::TYPE) {
			return;
		}

		/** @var Note $stream */
		foreach ($stream->getHashTags() as $hashtag) {
			$qb = $this->getStreamTagsInsertSql();
			$streamId = $qb->prim($stream->getId());
			$qb->setValue('stream_id', $qb->createNamedParameter($streamId));
			$qb->setValue('hashtag', $qb->createNamedParameter($hashtag));
			try {
				$qb->executeStatement();
			} catch (DBException $e) {
				Server::get(LoggerInterface::class)
					->log(1, 'Social - Duplicate hashtag on Stream ' . json_encode($stream));
			}
		}
	}

	public function emptyStreamTags(): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_TAGS);

		$qb->executeStatement();
	}
}
