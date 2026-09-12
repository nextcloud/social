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

class Version1000Date20260611000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$oldTables = [
			'social_3_action',
			'social_3_actor',
			'social_3_cache_actor',
			'social_3_cache_doc',
			'social_3_client',
			'social_3_follow',
			'social_3_hashtag',
			'social_3_instance',
			'social_3_req_queue',
			'social_3_stream',
			'social_3_stream_act',
			'social_3_stream_dest',
			'social_3_stream_queue',
			'social_3_stream_tag',
		];

		foreach ($oldTables as $tableName) {
			if ($schema->hasTable($tableName)) {
				$schema->dropTable($tableName);
			}
		}

		return $schema;
	}
}
