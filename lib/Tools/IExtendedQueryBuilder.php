<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

use DateTime;
use Exception;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Interface IExtendedQueryBuilder
 *
 * @deprecated
 * @package OCA\Social\Tools
 */
interface IExtendedQueryBuilder extends IQueryBuilder {
	/**
	 * @param string $alias
	 *
	 * @return IExtendedQueryBuilder
	 */
	public function setDefaultSelectAlias(string $alias): IExtendedQueryBuilder;


	/**
	 * @return string
	 */
	public function getDefaultSelectAlias(): string;


	/**
	 * Limit the request to the Id
	 *
	 * @param int $id
	 *
	 * @return IExtendedQueryBuilder
	 */
	public function limitToId(int $id): IExtendedQueryBuilder;


	/**
	 * Limit the request to Ids
	 *
	 * @param list<int> $ids
	 *
	 * @return IExtendedQueryBuilder
	 */
	public function limitToIds(array $ids): IExtendedQueryBuilder;


	/**
	 * Limit the request to the Id (string)
	 *
	 * @param string $id
	 *
	 * @return IExtendedQueryBuilder
	 */
	public function limitToIdString(string $id): IExtendedQueryBuilder;


	/**
	 * Limit the request to the UserId
	 *
	 * @param string $userId
	 *
	 * @return IExtendedQueryBuilder
	 */
	public function limitToUserId(string $userId): IExtendedQueryBuilder;


	/**
	 * Limit the request to the creation
	 *
	 * @param int $delay
	 *
	 * @return IExtendedQueryBuilder
	 * @throws Exception
	 */
	public function limitToCreation(int $delay = 0): IExtendedQueryBuilder;


	/**
	 * @param string $field
	 * @param string $value
	 * @param bool $cs
	 * @param string $alias
	 */
	public function limitToDBField(string $field, string $value, bool $cs = true, string $alias = ''
	);


	/**
	 * @param string $field
	 * @param string $value
	 * @param bool $cs
	 * @param string $alias
	 *
	 * @return mixed
	 */
	public function filterDBField(string $field, string $value, bool $cs = true, string $alias = ''
	);

	public function exprLimitToDBField(
		string $field, string $value, bool $eq = true, bool $cs = true, string $alias = ''
	): string;

	public function limitToDBFieldArray(
		string $field, array $values, bool $cs = true, string $alias = ''
	);


	/**
	 * @param string $field
	 * @param string $value
	 * @param bool $cs
	 * @param string $alias
	 *
	 * @return mixed
	 */
	public function filterDBFieldArray(
		string $field, string $value, bool $cs = true, string $alias = ''
	);


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
		string $field, array $values, bool $eq = true, bool $cs = true, string $alias = ''
	): ICompositeExpression;


	/**
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 */
	public function limitToDBFieldInt(string $field, int $value, string $alias = '');


	/**
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 *
	 * @return mixed
	 */
	public function filterDBFieldInt(string $field, int $value, string $alias = '');


	/**
	 * @param string $field
	 * @param int $value
	 * @param string $alias
	 */
	public function exprLimitToDBFieldInt(string $field, int $value, string $alias = ''): string;


	/**
	 * @param string $field
	 */
	public function limitToDBFieldEmpty(string $field);


	/**
	 * @param string $field
	 *
	 * @return mixed
	 */
	public function filterDBFieldEmpty(string $field);


	/**
	 * @param string $field
	 * @param DateTime $date
	 * @param bool $orNull
	 */
	public function limitToDBFieldDateTime(string $field, DateTime $date, bool $orNull = false
	);


	/**
	 * @param int $timestamp
	 * @param string $field
	 */
	public function limitToSince(int $timestamp, string $field);


	/**
	 * @param string $field
	 * @param string $value
	 *
	 * @return mixed
	 */
	public function searchInDBField(string $field, string $value);
}
