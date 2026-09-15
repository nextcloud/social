<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Whether a stored video has been through the transcoder.
 *
 * This app has never re-encoded anything, deliberately — a video is stored as
 * it was uploaded, which is the honest thing to do with somebody's file. The
 * cost of that is not theoretical: **Pixelfed's default `media_types` accepts
 * `video/mp4` and nothing else**, so every `video/quicktime` posted from here,
 * which is every video straight off an iPhone, is dropped by its
 * `verifyAttachments()` without a word to anybody. Safari will not play WebM.
 *
 * A column rather than a queue table. What has to be remembered is one fact
 * about one stored file — has it been converted, is it not worth converting,
 * did converting it fail — and the work to do is a query over the documents
 * themselves rather than a list somebody has to keep in step with them. A
 * queue table would have to be filled by every path that stores a video and
 * emptied by every path that deletes one; a column cannot drift from the row
 * it is on.
 *
 * Existing rows are 0, which is "nobody has looked". That is correct for both
 * of the things they might be: a video that predates this and needs
 * converting, and one that is already an MP4 and will be marked as not needing
 * it the first time the job reads it.
 *
 * No index. The job's query is already narrowed by `media_type LIKE 'video/%'`
 * on a table where a video is a small minority of the rows, it runs once a
 * quarter-hour, and it reads one page.
 */
class Version1000Date20260915000011 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS)) {
			return null;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS);
		if ($table->hasColumn('transcoded')) {
			return null;
		}

		// 0 nobody has looked, 1 converted, 2 not worth converting, 3 tried and failed
		$table->addColumn('transcoded', Types::SMALLINT, [
			'notnull' => false,
			'default' => 0,
			'length' => 1,
		]);

		return $schema;
	}
}
