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
 * A post its author has put away.
 *
 * Deleting is the only thing this app has ever offered somebody who no longer
 * wants a post on their profile, and it is a bad answer to a common question:
 * a photograph from four years ago is not something to destroy because it no
 * longer belongs at the top of a profile. Pixelfed has had `StatusArchived`
 * for years and its app has a screen for it.
 *
 * A column rather than a table because it is one fact about one post, and
 * because every read that must not show an archived post is a read of
 * `social_stream` — a second table would mean a join on every timeline to
 * answer a question the row itself can answer.
 *
 * No index. Almost every row is `false` and always will be, so an index on it
 * would be read once and never used: the queries that filter it are already
 * selected by their own timeline's index, and this is a further condition on
 * a page of rows rather than the thing that finds them. The one query that
 * *is* about archived posts — an author's own list — is bounded by the author
 * as well, and `attributed_to_prim` is indexed.
 */
class Version1000Date20260915000005 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_STREAM)) {
			return null;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_STREAM);
		if ($table->hasColumn('archived')) {
			return null;
		}

		$table->addColumn('archived', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);

		return $schema;
	}
}
