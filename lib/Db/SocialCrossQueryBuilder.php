<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use Doctrine\DBAL\Query\QueryBuilder;
use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Stream;
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
	public function linkToCacheActors(string $alias = 'ca', string $link = '', bool $innerJoin = true) {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias);

		$expr = $this->expr();
		if ($link !== '') {
			if ($innerJoin) {
				$this->innerJoin(
					$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_CACHE_ACTORS, $pf,
					$expr->eq('ca.id_prim', $link)
				);
			} else {
				$this->leftJoin(
					$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_CACHE_ACTORS, $pf,
					$expr->eq('ca.id_prim', $link)
				);
			}
		} else {
			$this->from(CoreRequestBuilder::TABLE_CACHE_ACTORS, $pf);
		}

		$this->selectAlias($pf . '.id', 'cacheactor_id')
			->selectAlias($pf . '.nid', 'cacheactor_nid')
			->selectAlias($pf . '.type', 'cacheactor_type')
			->selectAlias($pf . '.icon_id', 'cacheactor_icon_id')
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

		$this->leftJoinCacheDocuments('icon_id', $pf, 'cacheactor_cachedocument_', 'cacd');
	}


	/**
	 * @param array $data
	 * @param string $prefix
	 *
	 * @return Stream
	 * @throws InvalidResourceException
	 */
	public function parseLeftJoinStream(
		array $data,
		string $prefix = '',
		int $exportFormat = 0,
	): Stream {
		$new = [];
		foreach ($data as $k => $v) {
			if (str_starts_with($k, $prefix)) {
				$new[substr($k, strlen($prefix))] = $v;
			}
		}

		if (($new['nid'] ?? '') === '') {
			throw new InvalidResourceException();
		}

		$stream = new Stream();
		$stream->importFromDatabase($new);
		$stream->setExportFormat($exportFormat);

		$actor = $this->parseLeftJoinCacheActors($data, $prefix . 'cacheactor_', $exportFormat);
		$stream->setActor($actor);

		return $stream;
	}


	/**
	 * @param array $data
	 *
	 * @return Person
	 * @throws InvalidResourceException
	 */
	public function parseLeftJoinCacheActors(
		array $data,
		string $prefix = '',
		int $exportFormat = 0,
	): Person {
		$new = [];

		foreach ($data as $k => $v) {
			if (str_starts_with($k, $prefix)) {
				$new[substr($k, strlen($prefix))] = $v;
			}
		}

		$actor = new Person();
		$actor->importFromDatabase($new);
		$actor->setExportFormat($exportFormat);

		if (!AP::$activityPub->isActor($actor)) {
			throw new InvalidResourceException('actor not actor');
		}

		try {
			$icon = $this->parseLeftJoinCacheDocuments($data, $prefix);
			$actor->setIcon($icon);
			// TODO: store avatar/header within table cache_actor
			$uuid = ($icon->getResizedCopy() === '') ? $icon->getLocalCopy() : $icon->getResizedCopy();
			$actor->setAvatar(
				$this->urlGenerator->linkToRouteAbsolute(
					'social.Api.mediaOpen',
					['uuid' => $uuid]
				)
			);
		} catch (InvalidResourceException $e) {
		}

		return $actor;
	}


	/**
	 * @param string $linkField
	 * @param string $linkAlias
	 */
	public function leftJoinCacheDocuments(
		string $linkField,
		string $linkAlias = '',
		string $prefix = 'cachedocument_',
		string $alias = 'cd',
	) {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();
		$pf = (($linkAlias === '') ? $this->getDefaultSelectAlias() : $linkAlias);

		$this->selectAlias($alias . '.id', $prefix . 'id')
			->selectAlias($alias . '.type', $prefix . 'type')
			->selectAlias($alias . '.mime_type', $prefix . 'mime_type')
			->selectAlias($alias . '.media_type', $prefix . 'media_type')
			->selectAlias($alias . '.url', $prefix . 'url')
			->selectAlias($alias . '.local_copy', $prefix . 'local_copy')
			->selectAlias($alias . '.resized_copy', $prefix . 'resized_copy')
			->selectAlias($alias . '.caching', $prefix . 'caching')
			->selectAlias($alias . '.public', $prefix . 'public')
			->selectAlias($alias . '.error', $prefix . 'error')
			->selectAlias($alias . '.creation', $prefix . 'creation')
			->leftJoin(
				$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, $alias,
				$expr->eq($pf . '.' . $linkField, $alias . '.id_prim')
			);
	}


	/**
	 * @param array $data
	 *
	 * @return Document
	 * @throws InvalidResourceException
	 */
	public function parseLeftJoinCacheDocuments(array $data, string $prefix = ''): Document {
		$new = [];
		$prefix .= 'cachedocument_';

		foreach ($data as $k => $v) {
			if (str_starts_with($k, $prefix)) {
				$new[substr($k, strlen($prefix))] = $v;
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
	public function leftJoinObjectStatus(
		string $link = 'object_id_prim',
		string $alias = '',
		string $leftAlias = 'os',
	) {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias) . '.';

		foreach (CoreRequestBuilder::$tables[CoreRequestBuilder::TABLE_STREAM] as $field) {
			$this->selectAlias($leftAlias . '.' . $field, 'objectstream_' . $field);
		}

		$this->leftJoin(
			$this->getDefaultSelectAlias(),
			CoreRequestBuilder::TABLE_STREAM,
			$leftAlias,
			$this->expr()->eq($pf . $link, $leftAlias . '.id_prim')
		);

		$this->leftJoinCacheActor(
			'attributed_to_prim',
			$leftAlias,
			'osca',
			'objectstream_'
		);
	}


	/**
	 * @param string $link
	 * @param string $alias
	 * @param string $leftAlias
	 * @param string $prefix
	 * @param Person|null $author
	 */
	protected function leftJoinCacheActor(
		string $link = 'attributed_to_prim',
		string $alias = '',
		string $leftAlias = 'ca',
		string $prefix = '',
		?Person $author = null,
	) {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias);

		foreach (CoreRequestBuilder::$tables[CoreRequestBuilder::TABLE_CACHE_ACTORS] as $field) {
			$this->selectAlias($leftAlias . '.' . $field, $prefix . 'cacheactor_' . $field);
		}

		$this->leftJoin(
			$this->getDefaultSelectAlias(),
			CoreRequestBuilder::TABLE_CACHE_ACTORS,
			$leftAlias,
			$this->expr()->eq($pf . '.' . $link, $leftAlias . '.id_prim')
		);

		$this->leftJoinCacheDocuments(
			'icon_id',
			$leftAlias,
			$prefix . 'cacheactor_cachedocument_',
			$leftAlias . 'cacd'
		);
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
			->selectAlias('sa.liked', 'streamaction_liked')
			->selectAlias('sa.boosted', 'streamaction_boosted')
			->selectAlias('sa.replied', 'streamaction_replied');
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
			->selectAlias($alias . '.liked', 'streamaction_liked')
			->selectAlias($alias . '.boosted', 'streamaction_boosted')
			->selectAlias($alias . '.replied', 'streamaction_replied');

		$orX = $expr->orX();
		$orX->add($expr->eq($alias . '.stream_id_prim', $pf . '.id_prim'));
		$orX->add($expr->eq($alias . '.stream_id_prim', $pf . '.object_id_prim'));

		$on = $expr->andX();
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
	public function innerJoinStreamDest(
		string $type, string $field = 'id_prim', string $aliasDest = 'sd', string $alias = '',
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
		string $type, string $field = 'id_prim', string $aliasDest = 'sd', string $alias = '',
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
		string $aliasFollowing = 'f', string $alias = '',
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
		string $aliasFollowing = 'f', string $alias = '',
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
