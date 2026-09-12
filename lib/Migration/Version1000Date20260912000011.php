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
 * `social_convo_state.muted`: the thread this account has stopped hearing from.
 *
 * Mastodon's `POST /api/v1/statuses/{id}/mute`, which `ActionService` refused
 * by name because nothing stored the answer — a silent no-op would have had
 * the client show a state that was never written.
 *
 * A column rather than a table: this row already records what one account has
 * done with one thread (how far it has read it, whether it has dismissed it),
 * and whether it wants to be told about it is the third thing of that kind.
 */
class Version1000Date20260912000011 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_convo_state')) {
			return null;
		}

		$table = $schema->getTable('social_convo_state');
		if ($table->hasColumn('muted')) {
			return null;
		}

		$table->addColumn('muted', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);

		return $schema;
	}
}
