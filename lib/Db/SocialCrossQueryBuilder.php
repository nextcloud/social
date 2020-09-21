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


use Doctrine\DBAL\Query\QueryBuilder;
use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCP\DB\QueryBuilder\ICompositeExpression;


/**
 * Class SocialCrossQueryBuilder
 *
 * @package OCA\Social\Db
 */
class SocialCrossQueryBuilder extends SocialCoreQueryBuilder {


	/**
	 * @param string $aliasDest
	 * @param string $aliasFollowing
	 */
	public function selectDestFollowing(string $aliasDest = 'sd', string $aliasFollowing = 'f') {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		if ($aliasDest !== '') {
			$this->from(CoreRequestBuilder::TABLE_STREAM_DEST, $aliasDest);
//			$this->inChunk($aliasDest);
		}
		if ($aliasFollowing !== '') {
			$this->from(CoreRequestBuilder::TABLE_FOLLOWS, $aliasFollowing);
		}
	}


	/**
	 * @param string $alias
	 * @param string $link
	 */
	public function linkToStreamTags(string $alias = 'st', string $link = '') {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$this->from(CoreRequestBuilder::TABLE_STREAM_TAGS, $alias);
		if ($link !== '') {
			$expr = $this->expr();
			$this->andWhere($expr->eq($alias . '.stream_id', $link));
		}
	}


	/**
	 * @param string $alias
	 * @param string $link
	 */
	public function linkToCacheActors(string $alias = 'ca', string $link = '') {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias);

		$expr = $this->expr();
		if ($link !== '') {
			$this->innerJoin(
				$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_CACHE_ACTORS, $pf,
				$expr->eq('ca.id_prim', $link)
			);
		} else {
			$this->from(CoreRequestBuilder::TABLE_CACHE_ACTORS, $pf);
		}

		$this->selectAlias($pf . '.nid', 'cacheactor_nid')
			 ->selectAlias($pf . '.id', 'cacheactor_id')
			 ->selectAlias($pf . '.type', 'cacheactor_type')
			 ->selectAlias($pf . '.account', 'cacheactor_account')
			 ->selectAlias($pf . '.following', 'cacheactor_following')
			 ->selectAlias($pf . '.followers', 'cacheactor_followers')
			 ->selectAlias($pf . '.inbox', 'cacheactor_inbox')
			 ->selectAlias($pf . '.shared_inbox', 'cacheactor_shared_inbox')
			 ->selectAlias($pf . '.outbox', 'cacheactor_outbox')
			 ->selectAlias($pf . '.featured', 'cacheactor_featured')
			 ->selectAlias($pf . '.url', 'cacheactor_url')
			 ->selectAlias($pf . '.preferred_username', 'cacheactor_preferred_username')
			 ->selectAlias($pf . '.name', 'cacheactor_name')
			 ->selectAlias($pf . '.summary', 'cacheactor_summary')
			 ->selectAlias($pf . '.public_key', 'cacheactor_public_key')
			 ->selectAlias($pf . '.source', 'cacheactor_source')
			 ->selectAlias($pf . '.details', 'cacheactor_details')
			 ->selectAlias($pf . '.creation', 'cacheactor_creation')
			 ->selectAlias($pf . '.local', 'cacheactor_local');
	}


	/**
	 * @param array $data
	 *
	 * @return Person
	 * @throws InvalidResourceException
	 */
	public function parseLeftJoinCacheActors(array $data): Person {
		$new = [];

		foreach ($data as $k => $v) {
			if (substr($k, 0, 11) === 'cacheactor_') {
				$new[substr($k, 11)] = $v;
			}
		}

		$actor = new Person();
		$actor->importFromDatabase($new);
		$actor->setAvatar(
			$this->urlGenerator->linkToRouteAbsolute('social.Local.globalActorAvatar') . '?id='
			. $actor->getId()
		);

		if (!AP::$activityPub->isActor($actor)) {
			throw new InvalidResourceException();
		}

		return $actor;
	}


	/**
	 * @param string $fieldDocumentId
	 * @param string $alias
	 */
	public function leftJoinCacheDocuments(string $fieldDocumentId, string $alias = '') {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();
		$func = $this->func();

		$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias);
		$this->selectAlias('cd.id', 'cachedocument_id')
			 ->selectAlias('cd.type', 'cachedocument_type')
			 ->selectAlias('cd.mime_type', 'cachedocument_mime_type')
			 ->selectAlias('cd.media_type', 'cachedocument_media_type')
			 ->selectAlias('cd.url', 'cachedocument_url')
			 ->selectAlias('cd.local_copy', 'cachedocument_local_copy')
			 ->selectAlias('cd.caching', 'cachedocument_caching')
			 ->selectAlias('cd.public', 'cachedocument_public')
			 ->selectAlias('cd.error', 'cachedocument_error')
			 ->selectAlias('cd.creation', 'cachedocument_creation')
			 ->leftJoin(
				 $this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, 'cd',
				 $expr->eq($func->lower($pf . '.' . $fieldDocumentId), $func->lower('cd.id'))
			 );
	}


	/**
	 * @param array $data
	 *
	 * @return Document
	 * @throws InvalidResourceException
	 */
	public function parseLeftJoinCacheDocuments(array $data): Document {
		$new = [];
		foreach ($data as $k => $v) {
			if (substr($k, 0, 14) === 'cachedocument_') {
				$new[substr($k, 14)] = $v;
			}
		}

		$document = new Document();
		$document->importFromDatabase($new);

		if ($document->getType() !== Image::TYPE) {
			throw new InvalidResourceException();
		}

		return $document;
	}


	/**
	 * @param string $alias
	 */
	public function leftJoinFollowStatus(string $alias = 'fs') {
		if ($this->getType() !== QueryBuilder::SELECT || !$this->hasViewer()) {
			return;
		}

		$expr = $this->expr();
		$actor = $this->getViewer();
		$pf = $this->getDefaultSelectAlias() . '.';

		$idPrim = $this->prim($actor->getId());

		$on = $expr->andX();
		$on->add($this->exprLimitToDBFieldInt('accepted', 1, $alias));
		$on->add($this->exprLimitToDBField('actor_id_prim', $idPrim, true, true, $alias));
		$on->add($expr->eq($pf . 'attributed_to_prim', $alias . '.object_id_prim'));

		$this->leftJoin($this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_FOLLOWS, $alias, $on);
	}


	/**
	 * @param string $alias
	 */
	public function selectStreamActions(string $alias = 'sa'): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias);
		$this->from(CoreRequestBuilder::TABLE_STREAM_ACTIONS, $pf);
		$this->selectAlias('sa.id', 'streamaction_id')
			 ->selectAlias('sa.actor_id', 'streamaction_actor_id')
			 ->selectAlias('sa.stream_id', 'streamaction_stream_id')
			 ->selectAlias('sa.values', 'streamaction_values');
	}


	/**
	 * @param string $alias
	 */
	public function leftJoinStreamAction(string $alias = 'sa'): void {
		if ($this->getType() !== QueryBuilder::SELECT || !$this->hasViewer()) {
			return;
		}

		$pf = $this->getDefaultSelectAlias();
		$expr = $this->expr();

		$this->selectAlias($alias . '.id', 'streamaction_id')
			 ->selectAlias($alias . '.actor_id', 'streamaction_actor_id')
			 ->selectAlias($alias . '.stream_id', 'streamaction_stream_id')
			 ->selectAlias($alias . '.values', 'streamaction_values');

		$orX = $expr->orX();
		$orX->add($expr->eq($alias . '.stream_id_prim', $pf . '.id_prim'));
		$orX->add($expr->eq($alias . '.stream_id_prim', $pf . '.object_id_prim'));

		$on = $expr->andX();
//		$this->inChunk('sa', $on);
		$viewer = $this->getViewer();
		$idPrim = $this->prim($viewer->getId());

		$on->add($expr->eq($alias . '.actor_id_prim', $this->createNamedParameter($idPrim)));
		$on->add($orX);

		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_STREAM_ACTIONS, $alias, $on
		);
	}


	/**
	 * @param string $type
	 * @param string $field
	 * @param string $aliasDest
	 * @param string $alias
	 */
	public function innerJoinSteamDest(
		string $type, string $field = 'id_prim', string $aliasDest = 'sd', string $alias = ''
	) {
		$this->andWhere($this->exprInnerJoinStreamDest($type, $field, $aliasDest, $alias));
	}


	/**
	 * @param string $type
	 * @param string $field
	 * @param string $aliasDest
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	public function exprInnerJoinStreamDest(
		string $type, string $field = 'id_prim', string $aliasDest = 'sd', string $alias = ''
	): ICompositeExpression {
		$expr = $this->expr();
		$andX = $expr->andX();
		$pf = (($alias === '') ? $this->getdefaultSelectAlias() : $alias) . '.';
		$andX->add($expr->eq($aliasDest . '.stream_id', $pf . $field));
		$andX->add($expr->eq($aliasDest . '.type', $this->createNamedParameter($type)));

		return $andX;
	}


	/**
	 * @param string $actorId
	 * @param string $type
	 * @param string $field
	 * @param string $aliasDest
	 * @param string $aliasFollowing
	 * @param string $alias
	 */
	public function innerJoinStreamDestFollowing(
		string $actorId, string $type, string $field = 'id_prim', string $aliasDest = 'sd',
		string $aliasFollowing = 'f', string $alias = ''
	) {
		$this->andWhere(
			$this->exprInnerJoinStreamDestFollowing(
				$actorId, $type, $field, $aliasDest, $aliasFollowing, $alias
			)
		);
	}


	/**
	 * @param string $actorId
	 * @param string $type
	 * @param string $field
	 * @param string $aliasDest
	 * @param string $aliasFollowing
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	public function exprInnerJoinStreamDestFollowing(
		string $actorId, string $type, string $field = 'id_prim', string $aliasDest = 'sd',
		string $aliasFollowing = 'f', string $alias = ''
	): ICompositeExpression {
		$expr = $this->expr();
		$andX = $expr->andX();

		$pf = (($alias === '') ? $this->getdefaultSelectAlias() : $alias) . '.';

		$idPrim = $this->prim($actorId);
		$andX->add($this->exprLimitToDBField('actor_id_prim', $idPrim, true, true, $aliasFollowing));
		$andX->add($this->exprLimitToDBFieldInt('accepted', 1, $aliasFollowing));
		$andX->add($expr->eq($aliasFollowing . '.follow_id_prim', $aliasDest . '.actor_id'));
		$andX->add($expr->eq($aliasDest . '.stream_id', $pf . $field));
		$andX->add($expr->eq($aliasDest . '.type', $this->createNamedParameter($type)));

		return $andX;
	}

}

