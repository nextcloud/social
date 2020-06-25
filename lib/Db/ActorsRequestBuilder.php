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


use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ActorsRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return IQueryBuilder
	 */
	protected function getActorsInsertSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert(self::TABLE_ACTORS);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getActorsUpdateSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(self::TABLE_ACTORS);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getActorsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'a.id', 'a.id_prim', 'a.user_id', 'a.preferred_username', 'a.name', 'a.summary',
			'a.public_key', 'a.avatar_version', 'a.private_key', 'a.creation'
		)
		   ->from(self::TABLE_ACTORS, 'a');

		$this->defaultSelectAlias = 'a';
		$qb->setDefaultSelectAlias('a');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getActorsDeleteSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete(self::TABLE_ACTORS);

		return $qb;
	}


	/**
	 * @param array $data
	 *
	 * @return Person
	 * @throws SocialAppConfigException
	 */
	public function parseActorsSelectSql($data): Person {
		$root = $this->configService->getSocialUrl();

		$actor = new Person();
		$actor->importFromDatabase($data);
		$actor->setType('Person');
		$actor->setInbox($actor->getId() . '/inbox')
			  ->setOutbox($actor->getId() . '/outbox')
			  ->setUserId($this->get('user_id', $data, ''))
			  ->setFollowers($actor->getId() . '/followers')
			  ->setFollowing($actor->getId() . '/following')
			  ->setSharedInbox($root . 'inbox')
			  ->setLocal(true)
			  ->setAvatarVersion($this->getInt('avatar_version', $data, -1))
			  ->setAccount(
				  $actor->getPreferredUsername() . '@' . $this->configService->getSocialAddress()
			  );
		$actor->setUrlSocial($root)
			  ->setUrl($actor->getId());

		return $actor;
	}

}

