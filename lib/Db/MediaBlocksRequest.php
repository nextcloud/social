<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The pictures this instance refuses, by what is in them rather than by who
 * posted them.
 *
 * Every other moderation tool here acts on an account, and the one thing none
 * of them does is stop a file coming back: the account is suspended, the
 * picture is posted again by the next one, and a moderator is deleting the
 * same image for the third time. This is the list that ends that, and it is
 * deliberately small — a hash, a reason, who decided it, and how many times it
 * has since been refused.
 *
 * The count is the interesting column. A blocklist with no evidence is one
 * nobody dares remove anything from a year later; one that says "refused 41
 * times" and one that says "never" are different decisions to review.
 */
class MediaBlocksRequest extends CoreRequestBuilder {
	/**
	 * Adds a hash to the list. Writing one twice is one row.
	 *
	 * @return bool whether this was new
	 */
	public function block(string $hash, string $reason, string $moderator): bool {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_MEDIA_BLOCKS)
			->setValue('hash', $qb->createNamedParameter($hash))
			->setValue('reason', $qb->createNamedParameter($reason))
			->setValue('moderator', $qb->createNamedParameter($moderator))
			->setValue('blocked', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->setValue('creation', $qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			return false;
		}

		return true;
	}

	public function unblock(string $hash): bool {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_MEDIA_BLOCKS)
			->where($qb->expr()->eq('hash', $qb->createNamedParameter($hash)));

		return $qb->executeStatement() > 0;
	}

	/**
	 * Whether this file is refused, counting the refusal as it answers.
	 *
	 * One statement for the question and one for the tally, and the tally is
	 * allowed to fail: a picture is refused whether or not the counter could
	 * be written, and a moderation decision must not depend on a statistic.
	 */
	public function isBlocked(string $hash): bool {
		$qb = $this->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_MEDIA_BLOCKS)
			->where($qb->expr()->eq('hash', $qb->createNamedParameter($hash)))
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$found = $cursor->fetch() !== false;
		$cursor->closeCursor();

		if ($found) {
			$this->countRefusal($hash);
		}

		return $found;
	}

	/**
	 * The whole list, newest first. It is a handful of rows on any instance
	 * that has one at all.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getAll(int $limit = 500): array {
		$qb = $this->getQueryBuilder();
		$qb->select('hash', 'reason', 'moderator', 'blocked', 'creation')
			->from(self::TABLE_MEDIA_BLOCKS)
			->orderBy('id', 'desc')
			->setMaxResults($limit);

		$rows = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$rows[] = [
				'hash' => (string)$data['hash'],
				'reason' => (string)$data['reason'],
				'moderator' => (string)$data['moderator'],
				'blocked' => (int)$data['blocked'],
				'creation' => (string)$data['creation'],
			];
		}
		$cursor->closeCursor();

		return $rows;
	}

	private function countRefusal(string $hash): void {
		try {
			$qb = $this->getQueryBuilder();
			$qb->update(self::TABLE_MEDIA_BLOCKS)
				->set('blocked', $qb->createFunction('blocked + 1'))
				->where($qb->expr()->eq('hash', $qb->createNamedParameter($hash)));
			$qb->executeStatement();
		} catch (DBException $e) {
			// the picture is refused either way
		}
	}
}
