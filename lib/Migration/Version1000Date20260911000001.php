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
 * `sensitive` had nowhere to be stored.
 *
 * The model has always carried the flag and both the client and the federated
 * representations emit it, but no column held it: a status came back from the
 * database sensitive only when it also had a content warning. So a client that
 * marked its media sensitive was answered 200, the post federated correctly
 * once, and every later read of it — the author's own timeline included —
 * reported the media as safe to show unasked.
 *
 * Existing rows default to 0, which is what they already behaved as.
 */
class Version1000Date20260911000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_stream')) {
			$table = $schema->getTable('social_stream');
			if (!$table->hasColumn('sensitive')) {
				$table->addColumn('sensitive', Types::SMALLINT, [
					'notnull' => true,
					'default' => 0,
					'length' => 1,
				]);
			}
		}

		return $schema;
	}
}
