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
 * Client secrets are stored as 'sha256:<hex>' — 71 characters — but the column
 * was sized for the 40-character plaintext (length 63), so registering an OAuth
 * client failed on MySQL/MariaDB with "data too long". Found by the integration
 * suite; widened to match auth_code and token (127).
 */
class Version1000Date20260908000001 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('social_client')) {
			$table = $schema->getTable('social_client');
			if ($table->hasColumn('app_client_secret')
				&& $table->getColumn('app_client_secret')->getLength() < 127) {
				$table->getColumn('app_client_secret')->setLength(127);
			}
		}

		return $schema;
	}
}
