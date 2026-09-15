<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The subjects an instance says it is about.
 *
 * A handful of rows on any instance that has them, read whole by the one page
 * that shows them — so there is no paging here and no index beyond the key.
 */
class DiscoverCategoriesRequest extends CoreRequestBuilder {
	/** What one instance may name. Past this it is not a shelf, it is a list. */
	public const MAX_CATEGORIES = 24;

	/** Hashtags in one category. */
	public const MAX_TAGS = 12;

	/**
	 * @param string[] $hashtags
	 * @return int the id it was stored under
	 */
	public function create(string $name, array $hashtags, int $position): int {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_DISCOVER_CATS)
			->setValue('name', $qb->createNamedParameter($name))
			->setValue('hashtags', $qb->createNamedParameter((string)json_encode(array_values($hashtags))))
			->setValue('position', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	public function delete(int $id): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_DISCOVER_CATS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() > 0;
	}

	/**
	 * Every category, in the order the administrator put them in.
	 *
	 * @return array<int, array{id: int, name: string, hashtags: string[]}>
	 */
	public function getAll(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'name', 'hashtags', 'position')
			->from(self::TABLE_DISCOVER_CATS)
			->orderBy('position', 'asc')
			->addOrderBy('id', 'asc')
			->setMaxResults(self::MAX_CATEGORIES);

		$categories = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$tags = json_decode((string)$data['hashtags'], true);
			$categories[] = [
				'id' => (int)$data['id'],
				'name' => (string)$data['name'],
				'hashtags' => is_array($tags) ? array_values(array_map('strval', $tags)) : [],
			];
		}
		$cursor->closeCursor();

		return $categories;
	}

	public function count(): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('*'), 'total')->from(self::TABLE_DISCOVER_CATS);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($data['total'] ?? 0);
	}
}
