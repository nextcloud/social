<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\HashtagsRequest;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The trends table holds a hashtag as long as the one it counts.
 *
 * `social_stream_tag.hashtag` — where a tag is stored when a post arrives — is
 * 127 characters, `social_hashtag.hashtag` was 63. The trends cron counts the
 * first table and writes the second, so a tag between the two lengths could be
 * stored on a post and never on its trend: the write failed with "value too
 * long" on PostgreSQL and on MySQL in strict mode, and the exception ended the
 * whole pass — every hashtag after it in iteration order went unwritten, on
 * that run and on every run after it.
 */
class Version1000Date20260918000001 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_HASHTAGS)) {
			return $schema;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_HASHTAGS);
		if (!$table->hasColumn('hashtag')) {
			return $schema;
		}

		$column = $table->getColumn('hashtag');
		if ($column->getLength() >= HashtagsRequest::HASHTAG_MAX_LENGTH) {
			return $schema;
		}

		$column->setLength(HashtagsRequest::HASHTAG_MAX_LENGTH);

		return $schema;
	}
}
