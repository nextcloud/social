<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Model\Moderation;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The instance's own decisions about accounts. Small by nature — a moderator
 * acts rarely — so the readers below are content to fetch the whole set.
 */
class ModerationRequest extends CoreRequestBuilder {
	/**
	 * Records the decision about an account, replacing any earlier one.
	 *
	 * Insert first and update on conflict, rather than delete and then insert:
	 * that order loses the old decision the moment the insert fails, and the
	 * failure was only logged — so the account ended up under no decision at
	 * all while the panel reported the new one as applied.
	 */
	public function save(Moderation $moderation): void {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_MODERATION)
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($moderation->getActorId())))
			->setValue('actor_id', $qb->createNamedParameter($moderation->getActorId()))
			->setValue('level', $qb->createNamedParameter($moderation->getLevel()))
			->setValue('comment', $qb->createNamedParameter($moderation->getComment()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		try {
			$qb->executeStatement();

			return;
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				$this->logger->error('could not record a moderation decision', ['exception' => $e]);

				throw $e;
			}
		}

		$update = $this->getQueryBuilder();
		$update->update(self::TABLE_MODERATION)
			->set('actor_id', $update->createNamedParameter($moderation->getActorId()))
			->set('level', $update->createNamedParameter($moderation->getLevel()))
			->set('comment', $update->createNamedParameter($moderation->getComment()))
			->set('creation', $update->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE))
			->where($update->expr()->eq(
				'actor_id_prim', $update->createNamedParameter($update->prim($moderation->getActorId()))
			));

		$update->executeStatement();
	}

	public function delete(string $actorId): void {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_MODERATION)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}

	/**
	 * @return Moderation[] newest decision first
	 */
	public function getAll(): array {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_id', 'level', 'comment', 'creation')
			->from(self::TABLE_MODERATION)
			->orderBy('creation', 'desc');

		$all = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$all[] = Moderation::fromRow($row);
		}
		$cursor->closeCursor();

		return $all;
	}

	/**
	 * @param string $level one of Moderation::LEVELS
	 *
	 * @return string[] the actor ids under that decision
	 */
	public function getActorIdsAt(string $level): array {
		$qb = $this->getQueryBuilder();
		$qb->select('actor_id')
			->from(self::TABLE_MODERATION)
			->where($qb->expr()->eq('level', $qb->createNamedParameter($level)));

		$ids = [];
		$cursor = $qb->executeQuery();
		while ($row = $cursor->fetch()) {
			$ids[] = (string)$row['actor_id'];
		}
		$cursor->closeCursor();

		return $ids;
	}

	/** @return string the level, or '' when the instance has decided nothing */
	public function levelOf(string $actorId): string {
		$qb = $this->getQueryBuilder();
		$qb->select('level')
			->from(self::TABLE_MODERATION)
			->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return $row === false ? '' : (string)$row['level'];
	}
}
