<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Db;

use DateInterval;
use DateTime;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;
use Exception;
use OC\DB\QueryBuilder\QueryBuilder;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCA\Social\Tools\IExtendedQueryBuilder;
use OCA\Social\Tools\IQueryRow;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class ExtendedQueryBuilder
 *
 * @package OCA\Social\Tools\Db
 */
class ExtendedQueryBuilder extends QueryBuilder implements IExtendedQueryBuilder {
	public string $defaultSelectAlias = '';

	/**
	 * @param string $alias
	 *
	 * @return IExtendedQueryBuilder
	 */
	public function setDefaultSelectAlias(string $alias): IExtendedQueryBuilder {
		$this->defaultSelectAlias = $alias;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getDefaultSelectAlias(): string {
		return $this->defaultSelectAlias;
	}

	/**
	 * Limit the request to the Id
	 *
	 * @param int $id
	 *
	 * @return ExtendedQueryBuilder
	 */
	public function limitToId(int $id): IExtendedQueryBuilder {
		$this->limitToDBFieldInt('id', $id);

		return $this;
	}

	public function limitToNid(int $id): void {
		$this->limitToDBFieldInt('nid', $id);
	}

	/**
	 * Limit the request to the Id (string)
	 *
	 * @param string $id
	 *
	 * @return ExtendedQueryBuilder
	 */
	public function limitToIdString(string $id): IExtendedQueryBuilder {
		$this->limitToDBField('id', $id, false);

		return $this;
	}

	/**
	 * Limit the request to the UserId
	 *
	 * @param string $userId
	 *
	 * @return ExtendedQueryBuilder
	 */
	public function limitToUserId(string $userId): IExtendedQueryBuilder {
		$this->limitToDBField('user_id', $userId, false);

		return $this;
	}

	/**
	 * Limit the request to the creation
	 *
	 * @param int $delay
	 *
	 * @return ExtendedQueryBuilder
	 * @throws Exception
	 */
	public function limitToCreation(int $delay = 0): IExtendedQueryBuilder {
		$date = new DateTime('now');
		$date->sub(new DateInterval('PT' . $delay . 'M'));

		$this->limitToDBFieldDateTime('creation', $date, true);

		return $this;
	}

	/**
	 * @param string $field
	 * @param string $value
	 * @param bool $cs - case sensitive
	 * @param string $alias
	 */
	public function limitToDBField(string $field, string $value, bool $cs = true, string $alias = '') {
		$expr = $this->exprLimitToDBField($field, $value, true, $cs, $alias);

		$this->andWhere($expr);
	}

	/**
	 * @param string $field
	 * @param string $value
	 * @param bool $cs - case sensitive
	 * @param string $alias
	 */
	public function filterDBField(string $field, string $value, bool $cs = true, string $alias = '',
	) {
		$expr = $this->exprLimitToDBField($field, $value, false, $cs, $alias);
		$this->andWhere($expr);
	}

	public function exprLimitToDBField(
		string $field, string $value, bool $eq = true, bool $cs = true, string $alias = '',
	): string {
		$expr = $this->expr();

		$pf = '';
		if ($this->getType() === DBALQueryBuilder::SELECT) {
			$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias) . '.';
		}
		$field = $pf . $field;

		$comp = 'eq';
		if ($eq === false) {
			$comp = 'neq';
		}

		if ($cs) {
			return $expr->$comp($field, $this->createNamedParameter($value));
		} else {
			$func = $this->func();

			return $expr->$comp(
				$func->lower($field), $func->lower($this->createNamedParameter($value))
			);
		}
	}

	/**
	 * @param string $field
	 * @param array $values
	 * @param bool $cs - case sensitive
	 * @param string $alias
	 */
	public function limitToDBFieldArray(
		string $field, array $values, bool $cs = true, string $alias = '',
	) {
		$expr = $this->exprLimitToDBFieldArray($field, $values, true, $cs, $alias);
		$this->andWhere($expr);
	}

	/**
	 * @param string $field
	 * @param array $values
	 * @param bool $eq
	 * @param bool $cs
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	public function exprLimitToDBFieldArray(
		string $field, array $values, bool $eq = true, bool $cs = true, string $alias = '',
	): ICompositeExpression {
		$expr = $this->expr();

		$pf = '';
		if ($this->getType() === DBALQueryBuilder::SELECT) {
			$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias) . '.';
		}
		$field = $pf . $field;

		$func = $this->func();
		$conditions = [];

		foreach ($values as $value) {
			$comp = $eq ? 'eq' : 'neq';
			if ($cs) {
				$conditions[] = $expr->$comp($field, $this->createNamedParameter($value));
			} else {
				$conditions[] = $expr->$comp(
					$func->lower($field), $func->lower($this->createNamedParameter($value))
				);
			}
		}

		if ($eq === false) {
			$junc = $expr->andX(...$conditions);
		} else {
			$junc = $expr->orX(...$conditions);
		}

		return $junc;
	}

	/**
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 */
	public function limitToDBFieldInt(string $field, int $value, string $alias = '') {
		$expr = $this->exprLimitToDBFieldInt($field, $value, $alias, true);
		$this->andWhere($expr);
	}

	public function exprLimitToDBFieldInt(string $field, int $value, string $alias = '', bool $eq = true,
	): string {
		$expr = $this->expr();

		$pf = '';
		if ($this->getType() === DBALQueryBuilder::SELECT) {
			$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias) . '.';
		}
		$field = $pf . $field;

		$comp = 'eq';
		if ($eq === false) {
			$comp = 'neq';
		}

		return $expr->$comp($field, $this->createNamedParameter($value, IQueryBuilder::PARAM_INT));
	}

	/**
	 * @param string $field
	 */
	public function limitToDBFieldEmpty(string $field) {
		$expr = $this->expr();
		$pf
			= ($this->getType() === DBALQueryBuilder::SELECT) ? $this->getDefaultSelectAlias()
															  . '.' : '';
		$field = $pf . $field;

		$this->andWhere($expr->eq($field, $this->createNamedParameter('')));
	}

	/**
	 * @param string $field
	 * @param DateTime $date
	 * @param bool $orNull
	 */
	public function limitToDBFieldDateTime(string $field, DateTime $date, bool $orNull = false) {
		$expr = $this->expr();
		$pf
			= ($this->getType() === DBALQueryBuilder::SELECT) ? $this->getDefaultSelectAlias()
															  . '.' : '';
		$field = $pf . $field;

		$conditions = [
			$expr->lte($field, $this->createNamedParameter($date, IQueryBuilder::PARAM_DATE))
		];

		if ($orNull === true) {
			$conditions[] = $expr->isNull($field);
		}

		$this->andWhere($expr->orX(...$conditions));
	}

	/**
	 * @param string $field
	 * @param string $value
	 */
	public function searchInDBField(string $field, string $value) {
		$expr = $this->expr();

		$pf = ($this->getType() === DBALQueryBuilder::SELECT) ? $this->getDefaultSelectAlias()
																. '.' : '';
		$field = $pf . $field;

		$this->andWhere($expr->iLike($field, $this->createNamedParameter($value)));
	}

	/**
	 * @param callable $method
	 *
	 * @return IQueryRow
	 * @throws RowNotFoundException
	 */
	public function getRow(callable $method): IQueryRow {
		$cursor = $this->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new RowNotFoundException();
		}

		return $method($data, $this);
	}

	/**
	 * @param callable $method
	 *
	 * @return IQueryRow[]
	 */
	public function getRows(callable $method): array {
		$rows = [];
		$cursor = $this->executeQuery();
		while ($data = $cursor->fetch()) {
			try {
				$rows[] = $method($data, $this);
			} catch (Exception $e) {
			}
		}
		$cursor->closeCursor();

		return $rows;
	}

	/**
	 * @param string $field
	 * @param array $value
	 * @param string $alias
	 */
	public function limitInArray(string $field, array $value, string $alias = ''): void {
		$this->andWhere($this->exprLimitInArray($field, $value, $alias));
	}

	/**
	 * @param string $field
	 * @param array $values
	 * @param string $alias
	 *
	 * @return string
	 */
	public function exprLimitInArray(string $field, array $values, string $alias = ''): string {
		if ($this->getType() === DBALQueryBuilder::SELECT) {
			$field = (($alias === '') ? $this->getDefaultSelectAlias() : $alias) . '.' . $field;
		}

		$expr = $this->expr();

		return $expr->in($field, $this->createNamedParameter($values, IQueryBuilder::PARAM_STR_ARRAY));
	}
}
