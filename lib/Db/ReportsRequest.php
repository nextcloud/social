<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\Report;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Storage for moderation reports.
 */
class ReportsRequest extends ReportsRequestBuilder {
	public function save(Report $report): int {
		$qb = $this->getReportsInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($report->getActorId()))
			->setValue('account_id', $qb->createNamedParameter($report->getAccountId()))
			->setValue('status_ids', $qb->createNamedParameter(json_encode($report->getStatusIds())))
			->setValue('comment', $qb->createNamedParameter($report->getComment()))
			->setValue('category', $qb->createNamedParameter($report->getCategory()))
			->setValue('local', $qb->createNamedParameter($report->isLocal() ? 1 : 0))
			->setValue('resolved', $qb->createNamedParameter($report->isResolved() ? 1 : 0))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		$qb->executeStatement();
		$id = $qb->getLastInsertId();
		$report->setId($id);
		if ($report->getCreation() === 0) {
			$report->setCreation(time());
		}

		return $id;
	}

	/**
	 * @throws ReportNotFoundException
	 */
	public function getById(int $id): Report {
		$qb = $this->getReportsSelectSql();
		$qb->andWhere($qb->expr()->eq('r.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ReportNotFoundException('report ' . $id . ' not found');
		}

		return $this->parseReportsSelectSql($data);
	}

	/**
	 * @return Report[]
	 */
	public function getAll(bool $includeResolved = false, int $limit = 200): array {
		$qb = $this->getReportsSelectSql();
		if (!$includeResolved) {
			$qb->andWhere($qb->expr()->eq('r.resolved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('r.id', 'desc');
		$qb->setMaxResults($limit);

		$reports = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$reports[] = $this->parseReportsSelectSql($data);
		}
		$cursor->closeCursor();

		return $reports;
	}

	public function countOpen(): int {
		$qb = $this->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from(self::TABLE_REPORTS)
			->where($qb->expr()->eq('resolved', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data === false ? [] : $data, 0);
	}

	public function setResolved(int $id, bool $resolved): void {
		$qb = $this->getReportsUpdateSql();
		$qb->set('resolved', $qb->createNamedParameter($resolved ? 1 : 0));
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * The reports an account is the subject of, and the ones it filed. Both are
	 * about something that no longer exists once the account is gone.
	 */
	public function deleteRelatedId(string $actorId): void {
		$qb = $this->getReportsDeleteSql();
		$qb->where(
			$qb->expr()->orX(
				$qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId)),
				$qb->expr()->eq('account_id', $qb->createNamedParameter($actorId))
			)
		);

		$qb->executeStatement();
	}

	public function delete(int $id): void {
		$qb = $this->getReportsDeleteSql();
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}
}
