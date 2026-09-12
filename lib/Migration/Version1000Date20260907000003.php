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
 * Bookmarks: one more per-viewer flag on social_stream_act, next to
 * liked/boosted/replied.
 */
class Version1000Date20260907000003 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_stream_act')) {
			$table = $schema->getTable('social_stream_act');
			if (!$table->hasColumn('bookmarked')) {
				$table->addColumn('bookmarked', Types::SMALLINT, [
					'notnull' => true,
					'default' => 0,
					'length' => 1,
				]);
			}
		}

		return $schema;
	}
}
