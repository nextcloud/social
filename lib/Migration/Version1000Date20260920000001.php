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
 * `disliked` on a viewer's row, beside `liked`.
 *
 * PeerTube publishes two counters and this app could only send one. The other
 * one needs the same thing every interaction flag needs: somewhere the viewer's
 * own answer can be read back with the post, in the join a timeline already
 * makes. Asking per post instead would be one query per video on a page of
 * them, which is exactly what this column exists to avoid for likes.
 *
 * Defaults to `0`: nobody has disliked anything on an instance upgrading to
 * this, and the flag is a local record of what this viewer sent.
 */
class Version1000Date20260920000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('social_stream_act')) {
			return null;
		}

		$table = $schema->getTable('social_stream_act');
		if ($table->hasColumn('disliked')) {
			return null;
		}

		$table->addColumn('disliked', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);

		return $schema;
	}
}
