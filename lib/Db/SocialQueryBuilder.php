<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

/**
 * Class SocialQueryBuilder
 *
 * @package OCA\Social\Db
 */
class SocialQueryBuilder extends SocialFiltersQueryBuilder {
	private int $format = 1;


	/**
	 * @param int $format
	 */
	public function setFormat(int $format) {
		$this->format = $format;
	}

	/**
	 * @return int
	 */
	public function getFormat(): int {
		return $this->format;
	}


	/**
	 * @param string $id
	 * @param string $field
	 */
	public function generatePrimaryKey(string $id, string $field = 'id_prim') {
		if ($id === '') {
			return;
		}

		$this->setValue($field, $this->createNamedParameter($this->prim($id)));
	}


	/**
	 * search using username
	 *
	 * @param string $username
	 */
	public function searchInPreferredUsername(string $username) {
		$dbConn = $this->getConnection();
		$this->searchInDBField('preferred_username', $dbConn->escapeLikeParameter($username) . '%');
	}

	/**
	 * Limit the request to the ActorId
	 *
	 * @param string $hashtag
	 * @param bool $all
	 */
	public function searchInHashtag(string $hashtag, bool $all = false) {
		$dbConn = $this->getConnection();
		$this->searchInDBField('hashtag', (($all) ? '%' : '') . $dbConn->escapeLikeParameter($hashtag) . '%');
	}


	/**
	 * Limit the request to the account
	 *
	 * @param string $account
	 */
	public function searchInAccount(string $account) {
		$dbConn = $this->getConnection();
		$this->searchInDBField('account', $dbConn->escapeLikeParameter($account) . '%');
	}
}
