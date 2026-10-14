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
 * The backfills a fresh install would otherwise never run.
 *
 * Nextcloud installs an app by running its migrations schema-only, so a
 * migration's `postSchemaChange()` does not run there — and several of this
 * app's are what raise the flags the fast queries wait for, or queue the job
 * that raises one. An instance installed after them kept the fallbacks for
 * good: the home timeline's join path (`dest_nid_filled`), the `archived IS
 * NULL` and attachments fallbacks and the joined Photos and Videos pages
 * (`stream_archived_filled`, `stream_media_kind_filled`), the bounded content
 * scan (`search_index_ready`, raised by `Cron\SearchIndex`) and the id-based
 * silenced-instance filter (`stream_author_hosts_filled`, raised by
 * `Cron\StreamAuthorHosts`).
 *
 * On an install the tables are empty, so each backfill finds nothing to do:
 * the first three flags go up at once, and the two jobs raise theirs on
 * their first run.
 */
class InstallBackfills implements IRepairStep {
	public function __construct(
		private Version1000Date20260917000001 $recipientNids,
		private Version1000Date20261008000401 $searchIndex,
		private Version1000Date20261008000503 $authorHosts,
		private Version1000Date20261013100500 $postColumns,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Run the backfills a fresh install skips';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$this->recipientNids->fill($output);
		$this->postColumns->fill($output);
		$this->searchIndex->fill();
		$this->authorHosts->fill();
	}
}
