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
 * Widens `social_hashtag.hashtag` from 63 to 127 characters on instances that
 * created it at 63.
 *
 * The trends cron counts tags from `social_stream_tag` (127 characters) and
 * writes them here; a tag between the two widths fails with "value too long"
 * on PostgreSQL and on MySQL in strict mode, and that ends the whole trends
 * pass. The squashed schema creates the column at 127 but only adds missing
 * columns to a table that exists, so an instance that created the table at 63
 * (0.24 and earlier) keeps 63 through the squash.
 */
class Version1000Date20261009500100 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable(CoreRequestBuilder::TABLE_HASHTAGS)) {
			return null;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_HASHTAGS);
		if (!$table->hasColumn('hashtag')) {
			return null;
		}

		$column = $table->getColumn('hashtag');
		if ($column->getLength() !== null && $column->getLength() >= HashtagsRequest::HASHTAG_MAX_LENGTH) {
			return null;
		}

		$column->setLength(HashtagsRequest::HASHTAG_MAX_LENGTH);

		return $schema;
	}
}
