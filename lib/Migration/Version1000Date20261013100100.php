<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Indexes a repository's records by CID, which `com.atproto.sync.getBlocks`
 * looks them up by.
 */
class Version1000Date20261013100100 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('social_atproto_record')) {
			return null;
		}
		$table = $schema->getTable('social_atproto_record');
		if ($table->hasIndex('social_atp_rec_dcid')) {
			return null;
		}
		$table->addIndex(['did', 'cid'], 'social_atp_rec_dcid');

		return $schema;
	}
}
