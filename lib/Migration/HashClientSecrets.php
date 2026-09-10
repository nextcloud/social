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
use Throwable;

/**
 * Rewrites plaintext OAuth client secrets, authorization codes and access
 * tokens into their sha256 form. New values are written hashed from the
 * start; this only exists for rows created before that. Runs on every
 * upgrade and is a no-op once nothing is left to convert — one query that
 * selects the plaintext rows and comes back empty.
 *
 * A row that cannot be rewritten is reported and left alone rather than
 * allowed to end the upgrade: the plaintext form is still understood on
 * lookup, so the client keeps working and the next upgrade tries again.
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
		$rows = $this->unhashedRows();
		if ($rows === []) {
			return;
		}

		$converted = 0;
		$failed = [];
		foreach ($rows as $row) {
			// one row that cannot be written must not end the upgrade with the
			// instance in maintenance mode. A row left behind still works — the
			// plaintext form is understood on lookup — and the next upgrade
			// picks it up again.
			try {
				$converted += $this->hashRow($row);
			} catch (Throwable $t) {
				$failed[] = (string)$row['id'];
				$output->warning(
					'could not hash the credentials of the Social OAuth client ' . $row['id']
					. ': ' . $t->getMessage()
				);
			}
		}

		if ($converted > 0) {
			$output->info('Hashed the credentials of ' . $converted . ' Social OAuth client(s)');
		}

		if ($failed !== []) {
			$output->warning(
				'The credentials of ' . count($failed) . ' Social OAuth client(s) are still stored '
				. 'in plaintext: ' . implode(', ', $failed) . '. They keep working, and the next '
				. 'upgrade will try again.'
			);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return int 1 if the row was rewritten
	 */
	private function hashRow(array $row): int {
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
			return 0;
		}

		$update->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])));
		$update->executeStatement();

		return 1;
	}

	/**
	 * The rows that still hold something in plaintext.
	 *
	 * Selected by the database rather than by reading the table and deciding
	 * here: this runs on every single upgrade, and on all but the first one
	 * there is nothing left to convert — which should cost one query that
	 * returns no rows, not the whole `social_client` table hydrated into PHP.
	 *
	 * @return array<array<string, mixed>>
	 */
	private function unhashedRows(): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('id', ...self::COLUMNS)
			->from(CoreRequestBuilder::TABLE_CLIENT);

		$prefix = $this->hashedPrefix();
		if ($prefix !== '') {
			$pattern = $qb->createNamedParameter(
				$this->connection->escapeLikeParameter($prefix) . '%'
			);

			$plaintext = [];
			foreach (self::COLUMNS as $column) {
				$plaintext[] = $qb->expr()->andX(
					$qb->expr()->nonEmptyString($column),
					$qb->expr()->notLike($column, $pattern)
				);
			}
			$qb->where($qb->expr()->orX(...$plaintext));
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * The marker a hashed value carries, asked of the hasher itself so the two
	 * cannot drift apart. Empty when the hasher no longer uses one, in which
	 * case every row is read and the decision is made here, as it used to be.
	 */
	private function hashedPrefix(): string {
		$hashed = $this->secretHasher->hash('probe');
		$separator = strpos($hashed, ':');
		if ($separator === false) {
			return '';
		}

		$prefix = substr($hashed, 0, $separator + 1);

		return $this->secretHasher->isHashed($prefix) ? $prefix : '';
	}
}
