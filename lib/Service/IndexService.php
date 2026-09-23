<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\StreamTagsRequest;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repairs missing timeline and hashtag side-index rows in a resumable walk.
 *
 * New and edited posts maintain these tables during their normal write path.
 * This bounded pass is for legacy rows and any gaps left by an older version;
 * unlike the administrator's explicit full rebuild, it never empties either
 * table. The cursor advances only after both indexes for a stream are written,
 * so a partial failure is safe to retry: both writers ignore duplicate keys.
 */
class IndexService {
	public const CHUNK_SIZE = 500;
	private const CURSOR_KEY = 'index_nid';

	public function __construct(
		private StreamRequest $streamRequest,
		private StreamDestRequest $streamDestRequest,
		private StreamTagsRequest $streamTagsRequest,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/** Process one bounded page and return the number of completely indexed streams. */
	public function repairNextChunk(): int {
		$afterNid = $this->config->getAppValue(Application::APP_ID, self::CURSOR_KEY, '0');
		$rows = $this->streamRequest->getIndexChunk($afterNid, self::CHUNK_SIZE);
		$processed = 0;

		foreach ($rows as $row) {
			try {
				$stream = $this->streamRequest->getStream($row['id_prim']);
				$this->streamDestRequest->generateStreamDest($stream);
				$this->streamTagsRequest->generateStreamTags($stream);
			} catch (Throwable $e) {
				// Keep the cursor before this row. The next pass retries it rather
				// than silently advancing past a stream whose side indexes are absent.
				$this->logger->error('[Cron\\Index] could not index stream ' . $row['nid'], [
					'nid' => $row['nid'],
					'exception' => $e,
				]);

				return $processed;
			}

			// Persist after each complete row. A killed process can repeat work,
			// but it cannot lose progress or leave the cursor ahead of an index.
			$this->config->setAppValue(Application::APP_ID, self::CURSOR_KEY, $row['nid']);
			$processed++;
		}

		return $processed;
	}
}
