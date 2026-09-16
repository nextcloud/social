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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The follows a home timeline is built from, answerable from an index alone.
 *
 * Every home page begins by asking which follower collections the viewer
 * follows — `WHERE actor_id_prim = ? AND type = 'Follow' AND accepted = 1`,
 * projecting `follow_id_prim`. The index it had, `(actor_id_prim, accepted)`,
 * narrows that to the viewer's follows and then has to fetch **each row** for
 * the other two columns. A `social_follow` row is about 250 bytes, of which
 * 158 are the text ids nothing in this query wants, so an account following
 * two thousand people read half a megabyte to answer with sixty-four kilobytes
 * of hashes.
 *
 * With `type` and `follow_id_prim` on the end of the index the answer is in the
 * index and no row is touched. The cost is one more index on a table that, on a
 * million-user instance following two hundred accounts each, has two hundred
 * million rows — which is exactly why the read wants to stay out of them.
 */
class Version1000Date20260917000004 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(CoreRequestBuilder::TABLE_FOLLOWS)) {
			return $schema;
		}

		$table = $schema->getTable(CoreRequestBuilder::TABLE_FOLLOWS);
		if (!$table->hasIndex('social_f_aatf')) {
			$table->addIndex(
				['actor_id_prim', 'accepted', 'type', 'follow_id_prim'],
				'social_f_aatf'
			);
		}

		return $schema;
	}
}
