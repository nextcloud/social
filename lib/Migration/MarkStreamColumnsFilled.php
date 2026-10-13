<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Sets `stream_archived_filled` and `stream_media_kind_filled` on a fresh
 * install.
 *
 * Nextcloud installs an app by running its migrations schema-only, so the
 * backfill in `Version1000Date20261013100500::postSchemaChange()` — which is
 * what raises the two flags — never runs there, and an instance installed
 * after it kept the slower filters and the joined Photos and Videos pages
 * for good. On an install the tables are empty, so the same walk finds
 * nothing to fill and raises both flags at once.
 */
class MarkStreamColumnsFilled implements IRepairStep {
	public function __construct(
		private Version1000Date20261013100500 $migration,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Mark the post columns a fresh install has nothing to backfill in';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$this->migration->fill($output);
	}
}
