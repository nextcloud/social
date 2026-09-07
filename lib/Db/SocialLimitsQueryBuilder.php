<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateInterval;
use DateTime;
use Exception;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class SocialLimitsQueryBuilder
 *
 * @package OCA\Social\Db
 */
class SocialLimitsQueryBuilder extends SocialCrossQueryBuilder {
	public function limitToType(string $type, string $alias = ''): self {
		$this->limitToDBField('type', $type, true, $alias);

		return $this;
	}


	/**
	 * Limit the request to the ActivityId
	 *
	 * @param string $activityId
	 */
	public function limitToActivityId(string $activityId) {
		$this->limitToDBField('activity_id', $activityId, false);
	}


	/**
	 * Limit the request to the Id (string)
	 *
	 * @param string $id
	 * @param bool $prim
	 */
	public function limitToInReplyTo(string $id, bool $prim = false) {
		if ($prim) {
			$this->limitToDBField('in_reply_to_prim', $this->prim($id), false);

			return;
		}

		$this->limitToDBField('in_reply_to', $id, false);
	}


	/**
	 * Limit the request to the sub-type
	 *
	 * @param string $subType
	 */
	public function limitToSubType(string $subType) {
		$this->limitToDBField('subtype', $subType);
	}


	/**
	 * Limit the request to clientId
	 *
	 * @param string $clientId
	 */
	public function limitToAppClientId(string $clientId) {
		$this->limitToDBField('app_client_id', $clientId);
	}


	/**
	 * @param string $type
	 */
	public function filterType(string $type) {
		$this->filterDBField('type', $type);
	}


	/**
	 * Limit the request to the Preferred Username
	 *
	 * @param string $username
	 */
	public function limitToPreferredUsername(string $username) {
		$this->limitToDBField('preferred_username', $username, false);
	}


	/**
	 * Limit the request to the ActorId
	 */
	public function limitToPublic() {
		$this->limitToDBFieldInt('public', 1);
	}


	/**
	 * Limit the request to the ActorId
	 */
	public function limitToIdPrim(string $id) {
		$this->limitToDBField('id_prim', $id);
	}


	/**
	 * Limit the request to the token
	 *
	 * @param string $token
	 * @param string $alias
	 */
	public function limitToToken(string $token, string $alias = '') {
		$this->limitToDBField('token', $token, true, $alias);
	}


	/**
	 * Limit the results to a given number
	 *
	 * @param int $limit
	 */
	public function limitResults(int $limit) {
		$this->setMaxResults($limit);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param string $hashtag
	 */
	public function limitToHashtag(string $hashtag) {
		$this->limitToDBField('hashtag', $hashtag, false);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param string $actorId
	 * @param string $alias
	 */
	public function limitToActorId(string $actorId, string $alias = '') {
		$this->limitToDBField('actor_id', $actorId, false, $alias);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param string $actorId
	 * @param string $alias
	 */
	public function limitToActorIdPrim(string $actorId, string $alias = '') {
		$this->limitToDBField('actor_id_prim', $actorId, false, $alias);
	}


	/**
	 * @param string $streamId
	 * @param string $alias
	 *
	 * @return void
	 */
	public function limitToStreamIdPrim(string $streamId, string $alias = '') {
		$this->limitToDBField('stream_id_prim', $streamId, false, $alias);
	}

	/**
	 * Limit the request to the FollowId
	 *
	 * @param string $followId
	 */
	public function limitToFollowId(string $followId) {
		$this->limitToDBField('follow_id', $followId, false);
	}


	/**
	 * Limit the request to the FollowId
	 *
	 * @param bool $accepted
	 * @param string $alias
	 */
	public function limitToAccepted(bool $accepted, string $alias = '') {
		$this->limitToDBField('accepted', ($accepted) ? '1' : '0', true, $alias);
	}


	/**
	 * Limit the request to the ServiceId
	 *
	 * @param string $objectId
	 */
	public function limitToObjectId(string $objectId) {
		$this->limitToDBField('object_id', $objectId, false);
	}


	/**
	 * Limit the request to the ActorId
	 *
	 * @param string $actorId
	 * @param string $alias
	 */
	public function limitToObjectIdPrim(string $actorId, string $alias = '') {
		$this->limitToDBField('object_id_prim', $actorId, false, $alias);
	}


	/**
	 * Limit the request to the account
	 *
	 * @param string $account
	 */
	public function limitToAccount(string $account) {
		$this->limitToDBField('account', $account, false);
	}


	/**
	 * Limit the request to the creation
	 *
	 * @param int $delay
	 *
	 * @throws Exception
	 */
	public function limitToCaching(int $delay = 0) {
		$date = new DateTime('now');
		$date->sub(new DateInterval('PT' . $delay . 'M'));

		$this->limitToDBFieldDateTime('caching', $date, true);
	}


	/**
	 * Limit the request to the url
	 *
	 * @param string $url
	 */
	public function limitToUrl(string $url) {
		$this->limitToDBField('url', $url);
	}


	/**
	 * Limit the request to the url
	 *
	 * @param string $actorId
	 * @param bool $prim
	 */
	public function limitToAttributedTo(string $actorId, bool $prim = false) {
		if ($prim) {
			$this->limitToDBField('attributed_to_prim', $this->prim($actorId));

			return;
		}

		$this->limitToDBField('attributed_to', $actorId, false);
	}


	/**
	 * Limit the request to the status
	 *
	 * @param int $status
	 */
	public function limitToStatus(int $status) {
		$this->limitToDBFieldInt('status', $status);
	}


	/**
	 * Limit the request to the instance
	 *
	 * @param string $address
	 */
	public function limitToAddress(string $address) {
		$this->limitToDBField('address', $address);
	}


	/**
	 * Limit the request to the instance
	 *
	 * @param bool $local
	 */
	public function limitToLocal(bool $local) {
		$this->limitToDBFieldInt('local', ($local) ? 1 : 0);
	}


	/**
	 * Limit the request to the parent_id
	 *
	 * @param string $parentId
	 */
	public function limitToParentId(string $parentId) {
		$this->limitToDBField('parent_id', $parentId);
	}


	/**
	 * @param ProbeOptions $options
	 *
	 */
	public function paginate(ProbeOptions $options) {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		if ($options->getSince() > 0) {
			$this->andWhere($expr->gt($pf . '.nid', $this->createNamedParameter($options->getSince())));
		}

		if ($options->getMaxId() > 0) {
			$this->andWhere($expr->lt($pf . '.nid', $this->createNamedParameter($options->getMaxId())));
		}

		if ($options->getMinId() > 0) {
			$options->setInverted(true);
			$this->andWhere($expr->gt($pf . '.nid', $this->createNamedParameter($options->getMinId())));
		}

		$this->setMaxResults($options->getLimit());
		$this->orderBy($pf . '.nid', ($options->isInverted()) ? 'asc' : 'desc');
	}


	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @throws DateTimeException
	 * @deprecated - use paginate()
	 */
	public function limitPaginate(int $since = 0, int $limit = 5) {
		$limit = max(1, min(ProbeOptions::MAX_LIMIT, $limit));
		try {
			if ($since > 0) {
				$dTime = new DateTime();
				$dTime->setTimestamp($since);
				$this->limitToDBFieldDateTime('published_time', $dTime);
			}
		} catch (Exception $e) {
			throw new DateTimeException();
		}

		$this->setMaxResults($limit);
		$pf = $this->getDefaultSelectAlias();
		$this->orderBy($pf . '.published_time', 'desc');
	}


	/**
	 * @param string $recipient
	 */
	public function filterDest(string $recipient) {
		$expr = $this->expr();

		$this->andWhere($expr->neq('actor_id', $this->createNamedParameter($this->prim($recipient))));
	}


	/**
	 * @param string $actorId
	 * @param string $type
	 * @param string $subType
	 * @param string $alias
	 */
	public function limitToDest(string $actorId, string $type, string $subType = '', string $alias = 'sd') {
		$this->andWhere($this->exprLimitToDest($actorId, $type, $subType, $alias));
	}


	/**
	 * @param string $actorId
	 * @param string $type
	 * @param string $subType
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	public function exprLimitToDest(string $actorId, string $type, string $subType = '', string $alias = 'sd',
	): ICompositeExpression {
		$expr = $this->expr();

		$conditions = [$expr->eq($alias . '.stream_id', $this->getDefaultSelectAlias() . '.id_prim')];
		if ($actorId) {
			$conditions[] = $this->exprLimitToDBField('actor_id', $this->prim($actorId), true, true, $alias);
		}
		$conditions[] = $this->exprLimitToDBField('type', $type, true, true, $alias);

		if ($subType !== '') {
			$conditions[] = $this->exprLimitToDBField('subtype', $subType, true, true, $alias);
		}

		$andX = $expr->andX(...$conditions);

		return $andX;
	}


	/**
	 * @param string $aliasDest
	 * @param string $aliasFollowing
	 * @param bool $allowPublic
	 * @param bool $allowDirect
	 */
	public function limitToViewer(
		string $aliasDest = 'sd', string $aliasFollowing = 'f', bool $allowPublic = false,
		bool $allowDirect = false, string $hiddenLevel = self::HIDDEN_TIMELINE,
	) {
		if (!$this->hasViewer()) {
			$this->selectDestFollowing($aliasDest);
			$this->innerJoinStreamDest('recipient', 'id_prim', 'sd', 's');
			$this->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', $aliasDest);

			return;
		}

		$this->selectDestFollowing($aliasDest, $aliasFollowing);
		$expr = $this->expr();
		$actor = $this->getViewer();

		$conditions = [
			$this->exprInnerJoinStreamDestFollowing(
				$actor->getId(), 'recipient', 'id_prim', $aliasDest, $aliasFollowing
			)
		];

		if ($allowPublic) {
			$conditions[] = $this->exprLimitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', $aliasDest);
		}

		if ($allowDirect) {
			$conditions[] = $this->exprLimitToDest($actor->getId(), 'dm', '', $aliasDest);
		}

		$orX = $expr->orX(...$conditions);

		$this->andWhere($orX);

		$this->filterHiddenActors($hiddenLevel);
	}

	/**
	 * Hide streams involving actors the viewer has blocked or muted (and actors who
	 * blocked the viewer). One LEFT JOIN anti-join against social_actor_relation,
	 * on the row's author and — for boosts — the boosted post's author. Levels:
	 * see the HIDDEN_* constants. No viewer, no filtering.
	 */
	public function filterHiddenActors(string $level = self::HIDDEN_TIMELINE): void {
		if (!$this->hasViewer()) {
			return;
		}

		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();
		$viewerPrim = $this->prim($this->getViewer()->getId());

		// a boost row's attributed_to is the booster; the boosted author sits on the
		// announced object's row
		$this->leftJoin(
			$pf, CoreRequestBuilder::TABLE_STREAM, 'hd_o',
			$expr->andX(
				$expr->eq('hd_o.id_prim', $pf . '.object_id_prim'),
				$expr->eq($pf . '.type', $this->createNamedParameter(Announce::TYPE))
			)
		);

		if ($level === self::HIDDEN_NOTIFICATIONS) {
			// a mute hides notifications only when it was created with notifications=true
			$onTypes = $expr->orX(
				$expr->in(
					'hd_r.type',
					$this->createNamedParameter(
						[ActorRelation::TYPE_BLOCK, ActorRelation::TYPE_BLOCKED_BY],
						IQueryBuilder::PARAM_STR_ARRAY
					)
				),
				$expr->andX(
					$expr->eq('hd_r.type', $this->createNamedParameter(ActorRelation::TYPE_MUTE)),
					$expr->eq('hd_r.notifications', $this->createNamedParameter(1))
				)
			);
		} else {
			$types = [ActorRelation::TYPE_BLOCK, ActorRelation::TYPE_BLOCKED_BY];
			if ($level === self::HIDDEN_TIMELINE) {
				$types[] = ActorRelation::TYPE_MUTE;
			}
			$onTypes = $expr->in(
				'hd_r.type', $this->createNamedParameter($types, IQueryBuilder::PARAM_STR_ARRAY)
			);
		}

		$this->leftJoin(
			$pf, CoreRequestBuilder::TABLE_ACTOR_RELATION, 'hd_r',
			$expr->andX(
				$expr->eq('hd_r.actor_id_prim', $this->createNamedParameter($viewerPrim)),
				$onTypes,
				$expr->orX(
					$expr->eq('hd_r.object_id_prim', $pf . '.attributed_to_prim'),
					$expr->eq('hd_r.object_id_prim', 'hd_o.attributed_to_prim')
				)
			)
		);
		$this->andWhere($expr->isNull('hd_r.id'));
	}
}
