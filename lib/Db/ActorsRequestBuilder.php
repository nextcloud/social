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


use daita\Traits\TArrayTools;
use OCA\Social\Model\ActivityPub\Actor;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ActorsRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return IQueryBuilder
	 */
	protected function getActorsInsertSql() {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert(self::TABLE_SERVER_ACTORS);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getActorsUpdateSql() {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(self::TABLE_SERVER_ACTORS);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function getActorsSelectSql() {
		$qb = $this->dbConnection->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'sa.id', 'sa.type', 'sa.user_id', 'sa.preferred_username', 'sa.public_key',
			'sa.private_key', 'sa.creation'
		)
		   ->from(self::TABLE_SERVER_ACTORS, 'sa');

		$this->defaultSelectAlias = 'sa';

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getActorsDeleteSql() {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete(self::TABLE_SERVER_ACTORS);

		return $qb;
	}


	/**
	 * @param array $data
	 *
	 * @return Actor
	 */
	protected function parseActorsSelectSql($data): Actor {
		$id = $this->configService->getRoot() . '@' . $data['preferred_username'];
		$actor = new Actor();
		$actor->setId($id)
			  ->setType($this->get('type', $data, ''))
			  ->setRoot($this->configService->getRoot());
		$actor->setUserId($data['user_id'])
			  ->setPreferredUsername($data['preferred_username'])
			  ->setPublicKey($data['public_key'])
			  ->setPrivateKey($data['private_key'])
			  ->setInbox($id . '/inbox')
			  ->setOutbox($id . '/outbox')
			  ->setFollowers($id . '/followers')
			  ->setFollowing($id . '/following')
			  ->setSharedInbox($this->configService->getRoot() . 'inbox')
			  ->setCreation($this->getInt('creation', $data, 0));

		return $actor;
	}

}

