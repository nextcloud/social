<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `social_req_queue.object_id_prim`: which post a queued delivery is about.
 *
 * A queued request carried the activity as a JSON blob and nothing that named
 * the object inside it, so "where did this post get to" was a question the
 * queue could not be asked -- the only way to the rows of one post was a
 * substring match on the blob. The prim is the md5 of the object's id, which is
 * how every other table in this app keys an id it needs to look up, and it is
 * indexed for the same reason they are.
 *
 * Empty for rows queued before this migration, which are at most a few days of
 * retries. They are never shown against a post, and nothing else reads them by
 * object.
 */
class Version1000Date20260914000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_req_queue')) {
			return null;
		}

		$table = $schema->getTable('social_req_queue');
		if (!$table->hasColumn('object_id_prim')) {
			$table->addColumn('object_id_prim', Types::STRING, [
				'notnull' => true,
				'default' => '',
				'length' => 32,
			]);
		}

		if (!$table->hasIndex('social_rq_object')) {
			$table->addIndex(['object_id_prim'], 'social_rq_object');
		}

		return $schema;
	}
}
