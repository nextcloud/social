<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * `social_starter_pack`: the collections read in from other servers, one row
 * each. See `Service\CollectionPackService` for what goes in and why.
 *
 * @psalm-type StoredPack = array{id: int, uri: string, slug: string, curator: string, curator_acct: string, name: string, summary: string, topic: string, language: string, url: string, members: list<string>, fetched: int}
 */
class StarterPacksRequest extends CoreRequestBuilder {
	/** The most packs ever read back; far more than a page of them draws. */
	public const MAX_PACKS = 200;

	/**
	 * Writes a collection, replacing the row of the same id if there is one.
	 *
	 * @param array{uri: string, slug: string, curator: string, curator_acct: string, name: string, summary: string, topic: string, language: string, url: string, members: list<string>, fetched: int} $pack
	 */
	public function save(array $pack): void {
		$prim = md5($pack['uri']);
		$existing = $this->idOf($prim);

		$qb = $this->getQueryBuilder();
		if ($existing === null) {
			$qb->insert(self::TABLE_STARTER_PACKS)
				->setValue('uri', $qb->createNamedParameter($pack['uri']))
				->setValue('uri_prim', $qb->createNamedParameter($prim))
				->setValue('slug', $qb->createNamedParameter($pack['slug']))
				->setValue('curator', $qb->createNamedParameter($pack['curator']))
				->setValue('curator_acct', $qb->createNamedParameter($pack['curator_acct']))
				->setValue('name', $qb->createNamedParameter($pack['name']))
				->setValue('summary', $qb->createNamedParameter($pack['summary']))
				->setValue('topic', $qb->createNamedParameter($pack['topic']))
				->setValue('language', $qb->createNamedParameter($pack['language']))
				->setValue('url', $qb->createNamedParameter($pack['url']))
				->setValue('members', $qb->createNamedParameter((string)json_encode(array_values($pack['members']), JSON_UNESCAPED_SLASHES)))
				->setValue('fetched', $qb->createNamedParameter($pack['fetched'], IQueryBuilder::PARAM_INT));
			$qb->executeStatement();

			return;
		}

		$qb->update(self::TABLE_STARTER_PACKS)
			->set('slug', $qb->createNamedParameter($pack['slug']))
			->set('curator', $qb->createNamedParameter($pack['curator']))
			->set('curator_acct', $qb->createNamedParameter($pack['curator_acct']))
			->set('name', $qb->createNamedParameter($pack['name']))
			->set('summary', $qb->createNamedParameter($pack['summary']))
			->set('topic', $qb->createNamedParameter($pack['topic']))
			->set('language', $qb->createNamedParameter($pack['language']))
			->set('url', $qb->createNamedParameter($pack['url']))
			->set('members', $qb->createNamedParameter((string)json_encode(array_values($pack['members']), JSON_UNESCAPED_SLASHES)))
			->set('fetched', $qb->createNamedParameter($pack['fetched'], IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($existing, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Every pack, by curator and then name.
	 *
	 * @return list<StoredPack>
	 */
	public function getAll(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('id', 'uri', 'slug', 'curator', 'curator_acct', 'name', 'summary', 'topic', 'language', 'url', 'members', 'fetched')
			->from(self::TABLE_STARTER_PACKS)
			->orderBy('curator_acct', 'asc')
			->addOrderBy('name', 'asc')
			->addOrderBy('id', 'asc')
			->setMaxResults(self::MAX_PACKS);

		$packs = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$packs[] = self::pack($data);
		}
		$cursor->closeCursor();

		return $packs;
	}

	public function deleteById(int $id): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STARTER_PACKS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function idOf(string $prim): ?int {
		$qb = $this->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_STARTER_PACKS)
			->where($qb->expr()->eq('uri_prim', $qb->createNamedParameter($prim)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$id = $cursor->fetchOne();
		$cursor->closeCursor();

		return ($id === false || $id === null) ? null : (int)$id;
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @return StoredPack
	 */
	private static function pack(array $data): array {
		$members = json_decode((string)($data['members'] ?? ''), true);

		return [
			'id' => (int)$data['id'],
			'uri' => (string)($data['uri'] ?? ''),
			'slug' => (string)$data['slug'],
			'curator' => (string)($data['curator'] ?? ''),
			'curator_acct' => (string)$data['curator_acct'],
			'name' => (string)$data['name'],
			'summary' => (string)($data['summary'] ?? ''),
			'topic' => (string)$data['topic'],
			'language' => (string)$data['language'],
			'url' => (string)($data['url'] ?? ''),
			'members' => is_array($members) ? array_values(array_map('strval', $members)) : [],
			'fetched' => (int)$data['fetched'],
		];
	}
}
