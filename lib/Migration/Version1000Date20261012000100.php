<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCA\Social\Cron\CompactStoredCopies;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Queues `Cron\CompactStoredCopies`, which takes the copies nothing reads
 * back out of the posts and notifications stored so far. No schema change:
 * the rows are rewritten by the job, off the upgrade.
 */
class Version1000Date20261012000100 extends SimpleMigrationStep {
	public function __construct(
		private IJobList $jobList,
	) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('social_stream')) {
			$this->jobList->add(CompactStoredCopies::class, ['after' => '0']);
		}
	}
}
