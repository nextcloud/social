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


use daita\MySmallPhpTools\Exceptions\DateTimeException;
use DateInterval;
use DateTime;
use Exception;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\Options\TimelineOptions;
use OCP\DB\QueryBuilder\ICompositeExpression;


/**
 * Class SocialLimitsQueryBuilder
 *
 * @package OCA\Social\Db
 */
class SocialLimitsQueryBuilder extends SocialCrossQueryBuilder {


	/**
	 * Limit the request to the Type
	 *
	 * @param string $type
	 *
	 * @return SocialQueryBuilder
	 */
	public function limitToType(string $type): self {
		$this->limitToDBField('type', $type, true);

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
		$this->limitToDBField('actor_id', $actorId, false, $alias);
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
			$this->limitToDBField('attributed_to_prim', $this->prim($actorId), false);

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
		$this->limitToDBField('local', ($local) ? '1' : '0');
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
	 * @param TimelineOptions $options
	 *
	 */
	public function paginate(TimelineOptions $options) {
		$expr = $this->expr();
		$pf = $this->getDefaultSelectAlias();

		if ($options->getSinceId() > 0) {
			$this->andWhere($expr->gt($pf . '.nid', $this->createNamedParameter($options->getSinceId())));
		}

		if ($options->getMaxId() > 0) {
			$this->andWhere($expr->lt($pf . '.nid', $this->createNamedParameter($options->getMaxId())));
		}

		if ($options->getMinId() > 0) {
			$options->setInverted(true);
			$this->andWhere($expr->gt($pf . '.nid', $this->createNamedParameter($options->getMaxId())));
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
	public function exprLimitToDest(string $actorId, string $type, string $subType = '', string $alias = 'sd'
	): ICompositeExpression {
		$expr = $this->expr();
		$andX = $expr->andX();

		$andX->add($expr->eq($alias . '.stream_id', $this->getDefaultSelectAlias() . '.id_prim'));
		if ($actorId) {
			$andX->add($this->exprLimitToDBField('actor_id', $this->prim($actorId), true, true, $alias));
		}
		$andX->add($this->exprLimitToDBField('type', $type, true, true, $alias));

		if ($subType !== '') {
			$andX->add($this->exprLimitToDBField('subtype', $subType, true, true, $alias));
		}

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
		bool $allowDirect = false
	) {
		if (!$this->hasViewer()) {
			$this->selectDestFollowing($aliasDest);
			$this->innerJoinSteamDest('recipient', 'id_prim', 'sd', 's');
			$this->limitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', $aliasDest);

			return;
		}

		$this->selectDestFollowing($aliasDest, $aliasFollowing);
		$expr = $this->expr();
		$orX = $expr->orX();
		$actor = $this->getViewer();

		$following = $this->exprInnerJoinStreamDestFollowing(
			$actor->getId(), 'recipient', 'id_prim', $aliasDest, $aliasFollowing
		);
		$orX->add($following);

		if ($allowPublic) {
			$orX->add($this->exprLimitToDest(ACore::CONTEXT_PUBLIC, 'recipient', '', $aliasDest));
		}

		if ($allowDirect) {
			$orX->add($this->exprLimitToDest($actor->getId(), 'dm', '', $aliasDest));
		}

		$this->andWhere($orX);
	}

}

