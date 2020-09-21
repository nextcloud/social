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


use daita\MySmallPhpTools\Exceptions\RowNotFoundException;
use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\DB\QueryBuilder\IQueryBuilder;

class CacheActorsRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getCacheActorsInsertSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_CACHE_ACTORS);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getCacheActorsUpdateSql(): IQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_CACHE_ACTORS);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return SocialQueryBuilder
	 */
	protected function getCacheActorsSelectSql(): SocialQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'ca.nid', 'ca.id', 'ca.account', 'ca.following', 'ca.followers', 'ca.inbox', 'ca.shared_inbox',
			'ca.outbox', 'ca.featured', 'ca.url', 'ca.type', 'ca.preferred_username', 'ca.name', 'ca.summary',
			'ca.public_key', 'ca.local', 'ca.details', 'ca.source', 'ca.creation'
		)
		   ->from(self::TABLE_CACHE_ACTORS, 'ca');

		$qb->setDefaultSelectAlias('ca');

		/** @deprecated */
		$this->defaultSelectAlias = 'ca';
		$qb->setDefaultSelectAlias('ca');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getCacheActorsDeleteSql(): IQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_CACHE_ACTORS);

		return $qb;
	}


	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Person
	 * @throws CacheActorDoesNotExistException
	 */
	protected function getCacheActorFromRequest(SocialQueryBuilder $qb): Person {
		/** @var Person $result */
		try {
			$result = $qb->getRow([$this, 'parseCacheActorsSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new CacheActorDoesNotExistException('Actor is not known');
		}

		return $result;
	}


	/**
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Person[]
	 */
	public function getCacheActorsFromRequest(SocialQueryBuilder $qb): array {
		/** @var Person[] $result */
		$result = $qb->getRows([$this, 'parseCacheActorsSelectSql']);

		return $result;
	}


	/**
	 * @param array $data
	 *
	 * @param SocialQueryBuilder $qb
	 *
	 * @return Person
	 */
	public function parseCacheActorsSelectSql(array $data, SocialQueryBuilder $qb): Person {
		$actor = new Person();
		$actor->importFromDatabase($data);

		$this->assignViewerLink($qb, $actor);

		try {
			$icon = $qb->parseLeftJoinCacheDocuments($data);
			$actor->setIcon($icon);
		} catch (InvalidResourceException $e) {
		}

		$this->assignDetails($actor, $data);

		return $actor;
	}


	/**
	 * @param SocialQueryBuilder $qb
	 * @param Person $actor
	 */
	private function assignViewerLink(SocialQueryBuilder $qb, Person $actor) {
		if ($actor->isLocal()) {
			$link = Person::LINK_LOCAL;
			if ($qb->hasViewer()
				&& $qb->getViewer()
					  ->getId() === $actor->getId()) {
				$link = Person::LINK_VIEWER;
			}
		} else {
			$link = Person::LINK_REMOTE;
		}

		$actor->setViewerLink($link);
	}

}

