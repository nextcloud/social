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
 * `social_list.group_id`: the Nextcloud group a list follows, or '' for a list
 * the owner made by hand.
 *
 * A group list is a list like any other -- same table, same timeline, same
 * `List` entity to a Mastodon client -- whose title and membership are the
 * group's rather than the owner's to edit. The column is what tells the two
 * apart, and the index is what `GroupListService` reads when a group changes:
 * every list bound to it, whoever owns it.
 */
class Version1000Date20260914000002 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_list')) {
			return null;
		}

		$table = $schema->getTable('social_list');
		if (!$table->hasColumn('group_id')) {
			$table->addColumn('group_id', Types::STRING, [
				'notnull' => true,
				'default' => '',
				'length' => 64,
			]);
		}

		if (!$table->hasIndex('social_list_g')) {
			$table->addIndex(['group_id'], 'social_list_g');
		}

		return $schema;
	}
}
