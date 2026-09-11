<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use DateTimeZone;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\ScheduledStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The posts an account has asked to have published later.
 *
 * Every read a client can reach takes the owner's actor id and compares it in
 * SQL. That is the whole of the access control on a scheduled post: nothing
 * reachable from a request can return, change or delete a row that belongs to
 * somebody else, so no caller has to remember to check. A waiting post is
 * unpublished text the author has not shown anyone yet — the one thing here
 * nobody but its owner may see.
 *
 * The two methods the cron uses — `getDue()` and `claim()` — are the
 * exceptions, and they are unscoped on purpose: the job runs for every account
 * at once and has no viewer to be scoped to.
 */
class ScheduledStatusesRequest extends ScheduledStatusesRequestBuilder {
	/**
	 * @return int the id the post was stored under, which is the id the API
	 *             hands the client
	 */
	public function save(ScheduledStatus $scheduled): int {
		$qb = $this->getScheduledInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($scheduled->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($scheduled->getActorId())))
			->setValue(
				'scheduled_at',
				$qb->createNamedParameter($this->dateTime($scheduled->getScheduledAt()), IQueryBuilder::PARAM_DATE)
			)
			->setValue('params', $qb->createNamedParameter($scheduled->exportParams()))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
		$id = $qb->getLastInsertId();
		$scheduled->setId($id);
		if ($scheduled->getCreation() === 0) {
			$scheduled->setCreation(time());
		}

		return $id;
	}

	/**
	 * Moves a waiting post to another time. The owner is in the statement, so
	 * a request naming somebody else's post changes no row.
	 */
	public function reschedule(int $id, string $actorId, int $scheduledAt): void {
		$qb = $this->getScheduledUpdateSql();
		$qb->set(
			'scheduled_at',
			$qb->createNamedParameter($this->dateTime($scheduledAt), IQueryBuilder::PARAM_DATE)
		);
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);

		$qb->executeStatement();
	}

	/**
	 * One waiting post of one account.
	 *
	 * @throws ItemNotFoundException there is no such post *of this account*,
	 *                               which is also the answer for one that
	 *                               belongs to somebody else
	 */
	public function getById(int $id, string $actorId): ScheduledStatus {
		$qb = $this->getScheduledSelectSql();
		$qb->andWhere($qb->expr()->eq('ss.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ss.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ItemNotFoundException('Record not found');
		}

		return $this->parseScheduledSelectSql($data);
	}

	/**
	 * A page of the account's waiting posts, soonest first — which is the
	 * order a client draws them in, and the order this is stored in.
	 *
	 * The cursor is the row id and not `scheduled_at`: two posts may be
	 * scheduled for the same second, and a cursor that cannot tell them apart
	 * either repeats one or skips one.
	 *
	 * @return ScheduledStatus[]
	 */
	public function getByActor(
		string $actorId,
		int $limit = 20,
		int $maxId = 0,
		int $minId = 0,
		int $sinceId = 0,
	): array {
		$qb = $this->getScheduledSelectSql();
		$qb->andWhere($qb->expr()->eq('ss.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		if ($maxId > 0) {
			$qb->andWhere($qb->expr()->lt('ss.id', $qb->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)));
		}
		if ($minId > 0) {
			$qb->andWhere($qb->expr()->gt('ss.id', $qb->createNamedParameter($minId, IQueryBuilder::PARAM_INT)));
		}
		if ($sinceId > 0) {
			$qb->andWhere($qb->expr()->gt('ss.id', $qb->createNamedParameter($sinceId, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('ss.scheduled_at', 'asc');
		$qb->addOrderBy('ss.id', 'asc');
		$qb->setMaxResults($limit);

		return $this->getScheduledFromRequest($qb);
	}

	/** How many posts the account has waiting, against Mastodon's total cap. */
	public function countByActor(string $actorId): int {
		$qb = $this->getScheduledSelectSql();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->andWhere($qb->expr()->eq('ss.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		return $this->countFromRequest($qb);
	}

	/**
	 * How many the account has waiting between two instants, against
	 * Mastodon's per-day cap.
	 *
	 * The day is bounded by the caller in PHP rather than by casting the
	 * column to a date in SQL, which is what Mastodon does: `::date` is
	 * PostgreSQL's, and the three other databases this app supports each spell
	 * it differently or not at all.
	 */
	public function countByActorBetween(string $actorId, int $from, int $until): int {
		$qb = $this->getScheduledSelectSql();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->andWhere($qb->expr()->eq('ss.actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))))
			->andWhere(
				$qb->expr()->gte(
					'ss.scheduled_at',
					$qb->createNamedParameter($this->dateTime($from), IQueryBuilder::PARAM_DATE)
				)
			)
			->andWhere(
				$qb->expr()->lt(
					'ss.scheduled_at',
					$qb->createNamedParameter($this->dateTime($until), IQueryBuilder::PARAM_DATE)
				)
			);

		return $this->countFromRequest($qb);
	}

	/**
	 * The posts whose time has come, across every account, soonest first.
	 *
	 * Bounded, because a cron slot is: an instance whose cron has been dead
	 * for a week must publish what it can and come back for the rest, rather
	 * than start a run it cannot finish and publish nothing.
	 *
	 * @return ScheduledStatus[]
	 */
	public function getDue(int $now, int $limit = 50): array {
		$qb = $this->getScheduledSelectSql();
		$qb->andWhere(
			$qb->expr()->lte(
				'ss.scheduled_at',
				$qb->createNamedParameter($this->dateTime($now), IQueryBuilder::PARAM_DATE)
			)
		);

		$qb->orderBy('ss.scheduled_at', 'asc');
		$qb->addOrderBy('ss.id', 'asc');
		$qb->setMaxResults($limit);

		return $this->getScheduledFromRequest($qb);
	}

	/**
	 * Takes a row out of the table for a publisher, and says whether this
	 * caller is the one who got it.
	 *
	 * The delete is what claims the post, not a flag: two cron workers that
	 * read the same due row both try this, the database lets exactly one of
	 * them affect a row, and only that one goes on to publish. A flag would
	 * need a second write to clear and would leave the post federated twice if
	 * the worker died between the two.
	 */
	public function claim(int $id): bool {
		$qb = $this->getScheduledDeleteSql();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() === 1;
	}

	/**
	 * @return bool whether a row of this account's was deleted; false is both
	 *              "no such post" and "not yours", which are one answer
	 */
	public function delete(int $id, string $actorId): bool {
		$qb = $this->getScheduledDeleteSql();
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId)))
		);

		return $qb->executeStatement() > 0;
	}

	/**
	 * Everything an account leaves behind here when it is deleted. Called from
	 * the account-deletion path, like every other deleteRelatedId() — a post
	 * that would be published under an account that no longer exists is worse
	 * than a lost draft.
	 */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getScheduledDeleteSql();
		$qb->where($qb->expr()->eq('actor_id_prim', $qb->createNamedParameter($qb->prim($actorId))));

		$qb->executeStatement();
	}

	/**
	 * @return ScheduledStatus[]
	 */
	private function getScheduledFromRequest(SocialQueryBuilder $qb): array {
		$scheduled = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$scheduled[] = $this->parseScheduledSelectSql($data);
		}
		$cursor->closeCursor();

		return $scheduled;
	}

	private function countFromRequest(SocialQueryBuilder $qb): int {
		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return ($data === false) ? 0 : $this->getInt('count', $data);
	}

	/**
	 * A timestamp as the date columns here hold one.
	 *
	 * Every other date in this schema is written from `new DateTime('now')`,
	 * which carries the server's timezone, and DBAL formats a DateTime in
	 * whatever zone the object has. A `DateTime('@…')` is always UTC, so a
	 * time built from a timestamp would be stored hours away from the `now`
	 * the job compares it with on any instance that is not on UTC — and a post
	 * published hours early is a post published before its author meant it to
	 * be.
	 */
	private function dateTime(int $timestamp): DateTime {
		return (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
	}
}
