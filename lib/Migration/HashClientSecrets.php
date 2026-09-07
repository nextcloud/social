<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Security\SecretHasher;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Rewrites plaintext OAuth client secrets, authorization codes and access
 * tokens into their sha256 form. New values are written hashed from the
 * start; this only exists for rows created before that. Runs on every
 * upgrade and is a no-op once nothing is left to convert.
 */
class HashClientSecrets implements IRepairStep {
	private const COLUMNS = ['app_client_secret', 'auth_code', 'token'];

	private IDBConnection $connection;
	private SecretHasher $secretHasher;

	public function __construct(IDBConnection $connection, SecretHasher $secretHasher) {
		$this->connection = $connection;
		$this->secretHasher = $secretHasher;
	}

	public function getName(): string {
		return 'Hash the stored Social OAuth client secrets and tokens';
	}

	public function run(IOutput $output): void {
		$select = $this->connection->getQueryBuilder();
		$select->select('id', ...self::COLUMNS)
			->from(CoreRequestBuilder::TABLE_CLIENT);

		$rows = [];
		$result = $select->executeQuery();
		while ($row = $result->fetch()) {
			$rows[] = $row;
		}
		$result->closeCursor();

		$converted = 0;
		foreach ($rows as $row) {
			$update = $this->connection->getQueryBuilder();
			$update->update(CoreRequestBuilder::TABLE_CLIENT);

			$dirty = false;
			foreach (self::COLUMNS as $column) {
				$value = (string)($row[$column] ?? '');
				if ($value === '' || $this->secretHasher->isHashed($value)) {
					continue;
				}
				$update->set($column, $update->createNamedParameter($this->secretHasher->hash($value)));
				$dirty = true;
			}

			if (!$dirty) {
				continue;
			}

			$update->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])));
			$update->executeStatement();
			$converted++;
		}

		if ($converted > 0) {
			$output->info('Hashed the credentials of ' . $converted . ' Social OAuth client(s)');
		}
	}
}
