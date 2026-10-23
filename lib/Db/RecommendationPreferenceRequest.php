<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Owner-scoped storage for explicit For You topic controls. */
class RecommendationPreferenceRequest extends CoreRequestBuilder {
	public const TABLE_PROFILE = 'social_rec_profile';
	public const TABLE_PREFERENCE = 'social_rec_pref';

	/** @return array{revision: int, generation: int, topics: list<array{topic_id: string, mode: string}>} */
	public function get(string $actorKey): array {
		$profile = $this->profile($actorKey);
		$qb = $this->getQueryBuilder();
		$qb->select('topic_id', 'mode')->from(self::TABLE_PREFERENCE)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->orderBy('topic_id');
		$result = $qb->executeQuery();
		$topics = [];
		while ($row = $result->fetch()) {
			$topics[] = ['topic_id' => (string)$row['topic_id'], 'mode' => (string)$row['mode']];
		}
		$result->closeCursor();

		return ['revision' => $profile['profile_ver'], 'generation' => $profile['generation'], 'topics' => $topics];
	}

	/**
	 * Applies a validated complete topic choice set iff the caller has the
	 * current revision. The compare-and-swap update is the serialization point.
	 *
	 * @param list<array{topic_id: string, mode: string}> $topics
	 * @return array{revision: int, generation: int, topics: list<array{topic_id: string, mode: string}>}|null null means revision conflict
	 */
	public function replace(string $actorKey, int $expectedRevision, array $topics): ?array {
		$this->ensureProfile($actorKey);
		$connection = $this->getConnection();
		$connection->beginTransaction();
		try {
			$qb = $this->getQueryBuilder();
			$qb->update(self::TABLE_PROFILE)
				->set('profile_ver', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
				->set('generation', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
				->andWhere($qb->expr()->eq('profile_ver', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
			if ($qb->executeStatement() !== 1) {
				$connection->rollBack();
				return null;
			}

			$qb = $this->getQueryBuilder();
			$qb->delete(self::TABLE_PREFERENCE)->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->executeStatement();
			foreach ($topics as $topic) {
				$qb = $this->getQueryBuilder();
				$qb->insert(self::TABLE_PREFERENCE)
					->setValue('actor_key', $qb->createNamedParameter($actorKey))
					->setValue('topic_id', $qb->createNamedParameter($topic['topic_id']))
					->setValue('mode', $qb->createNamedParameter($topic['mode']))
					->setValue('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
					->setValue('generation', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
					->executeStatement();
			}
			$connection->commit();
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}

		return $this->get($actorKey);
	}

	/** Invalidate in-flight writers before deleting preferences. */
	public function reset(string $actorKey, int $expectedRevision): ?array {
		$this->ensureProfile($actorKey);
		$connection = $this->getConnection();
		$connection->beginTransaction();
		try {
			$qb = $this->getQueryBuilder();
			$qb->update(self::TABLE_PROFILE)
				->set('profile_ver', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
				->set('generation', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
				->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
				->andWhere($qb->expr()->eq('profile_ver', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));
			if ($qb->executeStatement() !== 1) {
				$connection->rollBack();
				$current = $this->get($actorKey);
				if ($current['revision'] === $expectedRevision + 1
					&& $current['generation'] === $expectedRevision + 1
					&& $current['topics'] === []) {
					return $current;
				}
				return null;
			}
			$qb = $this->getQueryBuilder();
			$qb->delete(self::TABLE_PREFERENCE)->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->executeStatement();
			$qb = $this->getQueryBuilder();
			$qb->delete(RecommendationFeedbackRequest::TABLE)->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->executeStatement();
			$connection->commit();
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}

		return $this->get($actorKey);
	}

	/** Remove all state when its Nextcloud account is deleted. */
	public function purge(string $actorKey): void {
		$connection = $this->getConnection();
		$connection->beginTransaction();
		try {
			foreach ([RecommendationFeedbackRequest::TABLE, self::TABLE_PREFERENCE, self::TABLE_PROFILE] as $table) {
				$qb = $this->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->executeStatement();
			}
			$connection->commit();
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}
	}

	/** @return array{profile_ver: int, generation: int} */
	private function profile(string $actorKey): array {
		$this->ensureProfile($actorKey);
		$qb = $this->getQueryBuilder();
		$qb->select('profile_ver', 'generation')->from(self::TABLE_PROFILE)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return ['profile_ver' => (int)$row['profile_ver'], 'generation' => (int)$row['generation']];
	}

	private function ensureProfile(string $actorKey): void {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_key')->from(self::TABLE_PROFILE)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->setMaxResults(1);
		$result = $qb->executeQuery();
		$exists = $result->fetch() !== false;
		$result->closeCursor();
		if ($exists) {
			return;
		}

		$qb = $this->getQueryBuilder();
		try {
			$qb->insert(self::TABLE_PROFILE)
				->setValue('actor_key', $qb->createNamedParameter($actorKey))
				->setValue('profile_ver', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->setValue('generation', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->setValue('learning_mode', $qb->createNamedParameter('off'))
				->setValue('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
				->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}
}
