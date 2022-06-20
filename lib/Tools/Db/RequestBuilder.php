<?php

declare(strict_types=1);


/**
 * Some tools for myself.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Social\Tools\Db;

use DateInterval;
use DateTime;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class RequestBuilder
 * @deprecated - 19
 * @package OCA\Social\Tools\Db
 */
class RequestBuilder {


	/** @var string */
	protected $defaultSelectAlias;


	/**
	 * Limit the request to the Id
	 *
	 * @param IQueryBuilder $qb
	 * @param int $id
	 */
	protected function limitToId(IQueryBuilder $qb, int $id) {
		$this->limitToDBFieldInt($qb, 'id', $id);
	}


	/**
	 * Limit the request to the Id (string)
	 *
	 * @param IQueryBuilder $qb
	 * @param string $id
	 */
	protected function limitToIdString(IQueryBuilder $qb, string $id) {
		$this->limitToDBField($qb, 'id', $id, false);
	}


	/**
	 * Limit the request to the UserId
	 *
	 * @param IQueryBuilder $qb
	 * @param string $userId
	 */
	protected function limitToUserId(IQueryBuilder $qb, string $userId) {
		$this->limitToDBField($qb, 'user_id', $userId, false);
	}


	/**
	 * Limit the request to the creation
	 *
	 * @param IQueryBuilder $qb
	 * @param int $delay
	 *
	 * @throws Exception
	 */
	protected function limitToCreation(IQueryBuilder $qb, int $delay = 0) {
		$date = new DateTime('now');
		$date->sub(new DateInterval('PT' . $delay . 'M'));

		$this->limitToDBFieldDateTime($qb, 'creation', $date, true);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string $value
	 * @param bool $cs - case sensitive
	 * @param string $alias
	 */
	protected function limitToDBField(
		IQueryBuilder $qb, string $field, string $value, bool $cs = true, string $alias = ''
	) {
		$expr = $this->exprLimitToDBField($qb, $field, $value, true, $cs, $alias);
		$qb->andWhere($expr);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string $value
	 * @param bool $cs - case sensitive
	 * @param string $alias
	 */
	protected function filterDBField(
		IQueryBuilder $qb, string $field, string $value, bool $cs = true, string $alias = ''
	) {
		$expr = $this->exprLimitToDBField($qb, $field, $value, false, $cs, $alias);
		$qb->andWhere($expr);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string $value
	 * @param bool $eq
	 * @param bool $cs
	 * @param string $alias
	 *
	 * @return string
	 */
	protected function exprLimitToDBField(
		IQueryBuilder $qb, string $field, string $value, bool $eq = true, bool $cs = true, string $alias = ''
	): string {
		$expr = $qb->expr();

		$pf = '';
		if ($qb->getType() === QueryBuilder::SELECT) {
			$pf = (($alias === '') ? $this->defaultSelectAlias : $alias) . '.';
		}
		$field = $pf . $field;

		$comp = 'eq';
		if ($eq === false) {
			$comp = 'neq';
		}

		if ($cs) {
			return $expr->$comp($field, $qb->createNamedParameter($value));
		} else {
			$func = $qb->func();

			return $expr->$comp(
				$func->lower($field), $func->lower($qb->createNamedParameter($value))
			);
		}
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 */
	protected function limitToDBFieldInt(
		IQueryBuilder $qb, string $field, int $value, string $alias = ''
	) {
		$expr = $this->exprLimitToDBFieldInt($qb, $field, $value, $alias, true);
		$qb->andWhere($expr);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 */
	protected function filterDBFieldInt(
		IQueryBuilder $qb, string $field, int $value, string $alias = ''
	) {
		$expr = $this->exprLimitToDBFieldInt($qb, $field, $value, $alias, false);
		$qb->andWhere($expr);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 *
	 * @param bool $eq
	 *
	 * @return string
	 */
	protected function exprLimitToDBFieldInt(
		IQueryBuilder $qb, string $field, int $value, string $alias = '', bool $eq = true
	): string {
		$expr = $qb->expr();

		$pf = '';
		if ($qb->getType() === QueryBuilder::SELECT) {
			$pf = (($alias === '') ? $this->defaultSelectAlias : $alias) . '.';
		}
		$field = $pf . $field;

		$comp = 'eq';
		if ($eq === false) {
			$comp = 'neq';
		}

		return $expr->$comp($field, $qb->createNamedParameter($value));
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 */
	protected function limitToDBFieldEmpty(IQueryBuilder $qb, string $field) {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$qb->andWhere($expr->eq($field, $qb->createNamedParameter('')));
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 */
	protected function filterDBFieldEmpty(IQueryBuilder $qb, string $field) {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$qb->andWhere($expr->neq($field, $qb->createNamedParameter('')));
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param DateTime $date
	 * @param bool $orNull
	 */
	protected function limitToDBFieldDateTime(
		IQueryBuilder $qb, string $field, DateTime $date, bool $orNull = false
	) {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$orX = $expr->orX();
		$orX->add($expr->lte($field, $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATE)));

		if ($orNull === true) {
			$orX->add($expr->isNull($field));
		}

		$qb->andWhere($orX);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param int $timestamp
	 * @param string $field
	 *
	 * @throws Exception
	 */
	protected function limitToSince(IQueryBuilder $qb, int $timestamp, string $field) {
		$dTime = new DateTime();
		$dTime->setTimestamp($timestamp);

		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$orX = $expr->orX();
		$orX->add($expr->gte($field, $qb->createNamedParameter($dTime, IQueryBuilder::PARAM_DATE)));

		$qb->andWhere($orX);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param array $values
	 */
	protected function limitToDBFieldArray(IQueryBuilder $qb, string $field, array $values): void {
		$expr = $qb->expr();
		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$orX = $expr->orX();
		foreach ($values as $value) {
			$orX->add($expr->eq($field, $qb->createNamedParameter($value)));
		}

		$qb->andWhere($orX);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string $value
	 */
	protected function searchInDBField(IQueryBuilder $qb, string $field, string $value) {
		$expr = $qb->expr();

		$pf = ($qb->getType() === QueryBuilder::SELECT) ? $this->defaultSelectAlias . '.' : '';
		$field = $pf . $field;

		$qb->andWhere($expr->iLike($field, $qb->createNamedParameter($value)));
	}
}
