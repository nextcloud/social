<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration;

use Doctrine\DBAL\Logging\SQLLogger;

/**
 * Collects every statement Doctrine hands to the driver, with the parameters
 * and types it was bound with.
 *
 * Doctrine calls `startQuery()` from `executeQuery()` and `executeStatement()`
 * with the SQL as it goes out (the table prefix already replaced by the
 * server's connection), so a recorded statement can be run again verbatim —
 * which is what the query plan tests do with it.
 *
 * Only loaded through `QueryCounter`, which checks first that the interface
 * exists: DBAL 4 removed it.
 */
final class QueryRecorder implements SQLLogger {
	/** @var list<array{sql: string, params: array, types: array}> */
	public array $queries = [];

	#[\Override]
	public function startQuery($sql, ?array $params = null, ?array $types = null): void {
		// `"START TRANSACTION"`, `"COMMIT"`, `"SAVEPOINT"`: transaction control,
		// which Doctrine reports quoted, is not a query
		if (str_starts_with((string)$sql, '"')) {
			return;
		}

		$this->queries[] = ['sql' => (string)$sql, 'params' => $params ?? [], 'types' => $types ?? []];
	}

	#[\Override]
	public function stopQuery(): void {
	}
}
