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
 * Adds `social_actor.bot`.
 *
 * Mastodon's `bot` is a flag on the account and a client's profile editor
 * sends it with every save. It was accepted and dropped, so an account marked
 * as automated came back unmarked on the next read and every peer went on
 * believing a person was behind it.
 *
 * A flag rather than a type column: the actor document already derives its
 * `type` from it — `Service` when set, `Person` otherwise — and a type column
 * would be a second place for the same fact to be wrong in.
 */
class Version1000Date20260912000002 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_actor')) {
			return null;
		}

		$table = $schema->getTable('social_actor');
		if ($table->hasColumn('bot')) {
			return null;
		}

		// a default, because every actor that already exists is a person
		$table->addColumn('bot', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);

		return $schema;
	}
}
