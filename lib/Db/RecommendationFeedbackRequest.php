<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use InvalidArgumentException;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Stores the bounded, owner-scoped history needed to apply and undo feedback. */
class RecommendationFeedbackRequest extends CoreRequestBuilder {
	public const TABLE = 'social_rec_feedback';
	private const MAX_ACTIVE_SCOPES = 50;
	private const UNDO_SECONDS = 600;
	private const MAX_RECENT_ROWS = 500;

	/** @return array<string, mixed>|null */
	public function byEvent(string $actorKey, string $eventId): ?array {
		return $this->one($actorKey, 'event_id', $eventId);
	}

	/** @return array<string, mixed>|null */
	public function byFeedbackId(string $actorKey, string $feedbackId): ?array {
		return $this->one($actorKey, 'feedback_id', $feedbackId);
	}

	/**
	 * Insert one idempotent event, serializing writes on the owner's profile row.
	 *
	 * @return array{record: array<string, mixed>, feedback_version: int, duplicate: bool}
	 */
	public function record(string $actorKey, string $feedbackId, string $eventId, string $scope, string $scopeKey, string $operation, int $now): array {
		$connection = $this->getConnection();
		$connection->beginTransaction();
		try {
			$existing = $this->byEvent($actorKey, $eventId);
			if ($existing !== null) {
				$connection->commit();
				return ['record' => $existing, 'feedback_version' => $this->feedbackVersion($actorKey), 'duplicate' => true];
			}

			// This row update is the serialization point for concurrent feedback
			// from the same account, including the active-scope limit below.
			$qb = $this->getQueryBuilder();
			$qb->update(RecommendationPreferenceRequest::TABLE_PROFILE)
				->set('feedback_ver', $qb->func()->add('feedback_ver', $qb->expr()->literal(1)))
				->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)));
			if ($qb->executeStatement() !== 1) {
				throw new InvalidArgumentException('Recommendation profile is unavailable');
			}

			$revision = $this->feedbackVersion($actorKey);
			$current = $this->current($actorKey);
			$scopeRef = $scope . "\0" . $scopeKey;
			if (!isset($current[$scopeRef]) && count($current) >= self::MAX_ACTIVE_SCOPES) {
				throw new InvalidArgumentException('At most ' . self::MAX_ACTIVE_SCOPES . ' active feedback scopes are allowed');
			}

			$qb = $this->getQueryBuilder();
			$qb->insert(self::TABLE)
				->setValue('feedback_id', $qb->createNamedParameter($feedbackId))
				->setValue('actor_key', $qb->createNamedParameter($actorKey))
				->setValue('event_id', $qb->createNamedParameter($eventId))
				->setValue('scope', $qb->createNamedParameter($scope))
				->setValue('scope_key', $qb->createNamedParameter($scopeKey))
				->setValue('operation', $qb->createNamedParameter($operation))
				->setValue('revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT))
				->setValue('created_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->setValue('undo_until', $qb->createNamedParameter($now + self::UNDO_SECONDS, IQueryBuilder::PARAM_INT))
				->setValue('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->executeStatement();
			$connection->commit();
		} catch (DBException $e) {
			$connection->rollBack();
			if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$existing = $this->byEvent($actorKey, $eventId);
				if ($existing !== null) {
					return ['record' => $existing, 'feedback_version' => $this->feedbackVersion($actorKey), 'duplicate' => true];
				}
			}
			throw $e;
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}

		$this->prune($actorKey, $now);
		$record = $this->byFeedbackId($actorKey, $feedbackId);
		return ['record' => $record ?? [], 'feedback_version' => $this->feedbackVersion($actorKey), 'duplicate' => false];
	}

	/** @return array{undone: bool, expired: bool, feedback_version: int} */
	public function undo(string $actorKey, string $feedbackId, int $now): array {
		$record = $this->byFeedbackId($actorKey, $feedbackId);
		if ($record === null) {
			return ['undone' => false, 'expired' => true, 'feedback_version' => $this->feedbackVersion($actorKey)];
		}
		if ((int)$record['active'] === 0) {
			return ['undone' => true, 'expired' => false, 'feedback_version' => $this->feedbackVersion($actorKey)];
		}
		if ((int)$record['undo_until'] < $now) {
			return ['undone' => false, 'expired' => true, 'feedback_version' => $this->feedbackVersion($actorKey)];
		}

		$connection = $this->getConnection();
		$connection->beginTransaction();
		try {
			$profile = $this->getQueryBuilder();
			$profile->update(RecommendationPreferenceRequest::TABLE_PROFILE)
				->set('feedback_ver', $profile->func()->add('feedback_ver', $profile->expr()->literal(1)))
				->set('updated_at', $profile->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($profile->expr()->eq('actor_key', $profile->createNamedParameter($actorKey)));
			if ($profile->executeStatement() !== 1) {
				throw new InvalidArgumentException('Recommendation profile is unavailable');
			}
			$qb = $this->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('active', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
				->andWhere($qb->expr()->eq('feedback_id', $qb->createNamedParameter($feedbackId)))
				->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gte('undo_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
			$changed = $qb->executeStatement() === 1;
			if (!$changed) {
				$connection->rollBack();
				$latest = $this->byFeedbackId($actorKey, $feedbackId);
				$undone = $latest !== null && (int)$latest['active'] === 0;
				return ['undone' => $undone, 'expired' => !$undone, 'feedback_version' => $this->feedbackVersion($actorKey)];
			}
			$connection->commit();
		} catch (\Throwable $e) {
			$connection->rollBack();
			throw $e;
		}

		return ['undone' => true, 'expired' => false, 'feedback_version' => $this->feedbackVersion($actorKey)];
	}

	/** @return array<string, array<string, mixed>> keyed by "scope\0scope_key" */
	public function current(string $actorKey): array {
		$qb = $this->getQueryBuilder();
		$qb->select('feedback_id', 'event_id', 'scope', 'scope_key', 'operation', 'revision', 'created_at', 'undo_until', 'active')
			->from(self::TABLE)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->orderBy('revision', 'DESC')
			->setMaxResults(self::MAX_RECENT_ROWS);
		$result = $qb->executeQuery();
		$current = [];
		while ($row = $result->fetch()) {
			if ((int)$row['active'] !== 1) {
				continue;
			}
			$key = (string)$row['scope'] . "\0" . (string)$row['scope_key'];
			$current[$key] ??= self::normalise($row);
		}
		$result->closeCursor();

		return $current;
	}

	public function feedbackVersion(string $actorKey): int {
		$qb = $this->getQueryBuilder();
		$qb->select('feedback_ver')->from(RecommendationPreferenceRequest::TABLE_PROFILE)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? 0 : (int)$row['feedback_ver'];
	}

	/** Remove all feedback and its idempotency history for an owner. */
	public function purge(string $actorKey): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))->executeStatement();
	}

	/** Remove superseded history once its ten-minute Undo window is closed. */
	private function prune(string $actorKey, int $now): void {
		$latest = $this->current($actorKey);
		$keep = [];
		foreach ($latest as $record) {
			$keep[$record['feedback_id']] = true;
		}
		$qb = $this->getQueryBuilder();
		$qb->select('feedback_id', 'created_at', 'undo_until')->from(self::TABLE)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->setMaxResults(self::MAX_RECENT_ROWS);
		$result = $qb->executeQuery();
		$remove = [];
		while ($row = $result->fetch()) {
			if (!isset($keep[(string)$row['feedback_id']]) && (int)$row['undo_until'] < $now) {
				$remove[] = (string)$row['feedback_id'];
			}
		}
		$result->closeCursor();
		foreach (array_chunk($remove, 100) as $ids) {
			$delete = $this->getQueryBuilder();
			$delete->delete(self::TABLE)
				->where($delete->expr()->eq('actor_key', $delete->createNamedParameter($actorKey)))
				->andWhere($delete->expr()->in('feedback_id', $delete->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)))
				->executeStatement();
		}
	}

	/** @return array<string, mixed>|null */
	private function one(string $actorKey, string $column, string $value): ?array {
		$qb = $this->getQueryBuilder();
		$qb->select('feedback_id', 'event_id', 'scope', 'scope_key', 'operation', 'revision', 'created_at', 'undo_until', 'active')->from(self::TABLE)
			->where($qb->expr()->eq('actor_key', $qb->createNamedParameter($actorKey)))
			->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)))->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : self::normalise($row);
	}

	/** @return array<string, mixed> */
	private static function normalise(array $row): array {
		foreach (['feedback_id', 'event_id', 'scope', 'scope_key', 'operation'] as $field) {
			$row[$field] = (string)$row[$field];
		}
		foreach (['revision', 'created_at', 'undo_until', 'active'] as $field) {
			$row[$field] = (int)$row[$field];
		}
		return $row;
	}
}
