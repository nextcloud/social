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
 * Whether a report was passed on to the instance the reported account is on.
 *
 * Mastodon's Report entity carries `forwarded`, and until now this app
 * answered a constant `false` there because nothing forwarded. It is stored
 * rather than derived: the delivery either reached the remote inbox or it did
 * not, and neither the reporter nor a moderator can tell which from the rest
 * of the row. Every report that predates this column was not forwarded, which
 * is exactly what the default says.
 */
class Version1000Date20260911000020 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_report')) {
			return null;
		}

		$table = $schema->getTable('social_report');
		if ($table->hasColumn('forwarded')) {
			return null;
		}

		$table->addColumn('forwarded', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
			'length' => 1,
		]);

		return $schema;
	}
}
