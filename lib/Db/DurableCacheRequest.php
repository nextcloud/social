<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The table behind `DurableCache` when there is no memcache.
 *
 * Keys arrive hashed and values encoded: this class stores strings with an
 * expiry and knows nothing about what they mean. A row whose `expires` has
 * passed is invisible to every read before the purge gets to it.
 *
 * @see \OCA\Social\Service\DurableCache
 */
class DurableCacheRequest extends CoreRequestBuilder {
	/** The stored value, or null when there is none or it has expired. */
	public function read(string $key, int $now): ?string {
		$qb = $this->getQueryBuilder();
		$qb->select('cache_value')
			->from(self::TABLE_DURABLE_CACHE)
			->where($qb->expr()->eq('cache_key', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->gt('expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false || $data['cache_value'] === null) ? null : (string)$data['cache_value'];
	}

	/**
	 * Stores a value, replacing whatever the key held.
	 *
	 * An update first and an insert only when it touched nothing, the insert
	 * one that skips a conflict rather than raising it: two requests writing
	 * the same key at once is ordinary here, and a caught unique violation
	 * would abort the surrounding transaction on PostgreSQL.
	 */
	public function write(string $key, string $value, int $expires): void {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_DURABLE_CACHE)
			->set('cache_value', $qb->createNamedParameter($value))
			->set('expires', $qb->createNamedParameter($expires, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('cache_key', $qb->createNamedParameter($key)));

		if ($qb->executeStatement() > 0) {
			return;
		}

		$this->insertIfAbsent($key, $value, $expires);
	}

	/**
	 * Stores a value only when the key has no row at all, expired or not.
	 *
	 * @return bool whether this call wrote it
	 */
	public function insertIfAbsent(string $key, string $value, int $expires): bool {
		return $this->dbConnection->insertIgnoreConflict(self::TABLE_DURABLE_CACHE, [
			'cache_key' => $key,
			'cache_value' => $value,
			'expires' => $expires,
		]) > 0;
	}

	/** Replaces the value of a live row and leaves its expiry alone. */
	public function replaceValue(string $key, string $value, int $now): bool {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_DURABLE_CACHE)
			->set('cache_value', $qb->createNamedParameter($value))
			->where($qb->expr()->eq('cache_key', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->gt('expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	public function delete(string $key): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_DURABLE_CACHE)
			->where($qb->expr()->eq('cache_key', $qb->createNamedParameter($key)));

		$qb->executeStatement();
	}

	/**
	 * Deletes what has expired.
	 *
	 * @return int how many rows went
	 */
	public function purge(int $now): int {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_DURABLE_CACHE)
			->where($qb->expr()->lte('expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
