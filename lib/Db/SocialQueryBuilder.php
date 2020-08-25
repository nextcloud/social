<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
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


namespace OCA\Social\Db;


/**
 * Class SocialQueryBuilder
 *
 * @package OCA\Social\Db
 */
class SocialQueryBuilder extends SocialFiltersQueryBuilder {


	/** @var int */
	private $format = 1;


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

