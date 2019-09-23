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


use daita\MySmallPhpTools\Exceptions\CacheItemNotFoundException;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Doctrine\DBAL\Query\QueryBuilder;
use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * Class StreamRequestBuilder
 *
 * @package OCA\Social\Db
 */
class StreamRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return IQueryBuilder
	 */
	protected function getStreamInsertSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert(self::TABLE_STREAM);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getStreamUpdateSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(self::TABLE_STREAM);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function getStreamSelectSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->selectDistinct('s.id')
		   ->addSelect(
			   's.type', 's.to', 's.to_array', 's.cc', 's.bcc', 's.content',
			   's.summary', 's.attachments', 's.published', 's.published_time', 's.cache',
			   's.object_id', 's.attributed_to', 's.in_reply_to', 's.source', 's.local',
			   's.instances', 's.creation', 's.hidden_on_timeline', 's.details', 's.hashtags'
		   )
		   ->from(self::TABLE_STREAM, 's');

		$this->defaultSelectAlias = 's';

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function countNotesSelectSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
		   ->from(self::TABLE_STREAM, 's');

		$this->defaultSelectAlias = 's';

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getStreamDeleteSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM);

		return $qb;
	}


	/**
	 * @param IQueryBuilder $qb
	 */
	protected function limitToViewer(IQueryBuilder $qb) {
		$actor = $this->viewer;

		$on = $this->exprJoinFollowing($qb, $actor);
		$on->add($this->exprLimitToRecipient($qb, ACore::CONTEXT_PUBLIC, false));
		$on->add($this->exprLimitToRecipient($qb, $actor->getId(), true));
		$qb->join($this->defaultSelectAlias, CoreRequestBuilder::TABLE_FOLLOWS, 'f', $on);
	}


	/**
	 * @param IQueryBuilder $qb
	 */
	protected function filterHiddenOnTimeline(IQueryBuilder $qb) {
		$actor = $this->viewer;

		if ($actor === null) {
			return;
		}

		$func = $qb->func();
		$expr = $qb->expr();

		$filter = $expr->orX();
		$filter->add($this->exprLimitToDBFieldInt($qb, 'hidden_on_timeline', 0));

		$filter->add(
			$expr->neq(
				$func->lower('attributed_to'),
				$func->lower($qb->createNamedParameter($actor->getId()))
			)
		);

		$follower = $expr->andX();
		$follower->add(
			$expr->eq(
				$func->lower('f.object_id'),
				$func->lower('attributed_to')
			)
		);
		$follower->add(
			$this->exprLimitToDBField($qb, 'actor_id', $actor->getId(), true, false, 'f')
		);
		$filter->add($follower);

		$qb->andwhere($filter);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $aliasDest
	 * @param string $aliasFollowing
	 */
	protected function selectDestFollowing(
		IQueryBuilder $qb, string $aliasDest = 'sd', string $aliasFollowing = 'f'
	) {
		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$qb->from(self::TABLE_STREAM_DEST, $aliasDest);
		$qb->from(self::TABLE_FOLLOWS, $aliasFollowing);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param Person $actor
	 * @param string $field
	 * @param string $aliasDest
	 * @param string $aliasFollowing
	 *
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	protected function exprInnerJoinDestFollowing(
		IQueryBuilder $qb, Person $actor, string $field = 'id_prim', string $aliasDest = 'sd',
		string $aliasFollowing = 'f', string $alias = ''
	): ICompositeExpression {

		$expr = $qb->expr();
		$andX = $expr->andX();

		$pf = (($alias === '') ? $this->defaultSelectAlias : $alias) . '.';
		$andX->add(
			$this->exprLimitToDBField(
				$qb, 'actor_id_prim', $this->prim($actor->getId()), true, true, $aliasFollowing
			)
		);
		$andX->add($this->exprLimitToDBFieldInt($qb, 'accepted', 1, $aliasFollowing));
		$andX->add($expr->eq($aliasFollowing . '.follow_id_prim', $aliasDest . '.actor_id'));
		$andX->add($expr->eq($aliasDest . '.stream_id', $pf . $field));

		return $andX;
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param Person $actor
	 */
	protected function leftJoinFollowing(IQueryBuilder $qb, Person $actor) {
		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$on = $this->exprJoinFollowing($qb, $actor);
		$qb->selectAlias('f.object_id', 'following_actor_id');
		$qb->leftJoin($this->defaultSelectAlias, CoreRequestBuilder::TABLE_FOLLOWS, 'f', $on);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param Person $actor
	 *
	 * @return ICompositeExpression
	 * @deprecated - use the new table social_stream_dest
	 */
	protected function exprJoinFollowing(IQueryBuilder $qb, Person $actor) {
		$expr = $qb->expr();
		$func = $qb->func();
		$pf = $this->defaultSelectAlias . '.';

		$on = $expr->orX();

		// list of possible recipient as a follower (to, to_array, cc, ...)
		$recipientFields = $expr->orX();
		$recipientFields->add($expr->eq($func->lower($pf . 'to'), $func->lower('f.follow_id')));
		$recipientFields->add($this->exprFieldWithinJsonFormat($qb, 'to_array', 'f.follow_id'));
		$recipientFields->add($this->exprFieldWithinJsonFormat($qb, 'cc', 'f.follow_id'));
		$recipientFields->add($this->exprFieldWithinJsonFormat($qb, 'bcc', 'f.follow_id'));

		// all possible follow, but linked by followers (actor_id) and accepted follow
		$crossFollows = $expr->andX();
		$crossFollows->add($recipientFields);
		$crossFollows->add(
			$this->exprLimitToDBField($qb, 'actor_id', $actor->getId(), true, false, 'f')
		);
		$crossFollows->add($this->exprLimitToDBFieldInt($qb, 'accepted', 1, 'f'));
		$on->add($crossFollows);

		return $on;
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string $fieldRight
	 * @param string $alias
	 *
	 * @return string
	 */
	protected function exprFieldWithinJsonFormat(
		IQueryBuilder $qb, string $field, string $fieldRight, string $alias = ''
	) {
		$func = $qb->func();
		$expr = $qb->expr();

		if ($alias === '') {
			$alias = $this->defaultSelectAlias;
		}

		$concat = $func->concat(
			$qb->createNamedParameter('%"'),
			$func->concat($fieldRight, $qb->createNamedParameter('"%'))
		);

		return $expr->iLike($alias . '.' . $field, $concat);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string $value
	 *
	 * @return string
	 */
	protected function exprValueWithinJsonFormat(IQueryBuilder $qb, string $field, string $value
	): string {
		$dbConn = $this->dbConnection;
		$expr = $qb->expr();

		return $expr->iLike(
			$field,
			$qb->createNamedParameter('%"' . $dbConn->escapeLikeParameter($value) . '"%')
		);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $field
	 * @param string $value
	 *
	 * @return string
	 */
	protected function exprValueNotWithinJsonFormat(IQueryBuilder $qb, string $field, string $value
	): string {
		$dbConn = $this->dbConnection;
		$expr = $qb->expr();
		$func = $qb->func();

		return $expr->notLike(
			$func->lower($field),
			$qb->createNamedParameter(
				'%"' . $func->lower($dbConn->escapeLikeParameter($value)) . '"%'
			)
		);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $recipient
	 * @param bool $asAuthor
	 * @param array $type
	 */
	protected function limitToRecipient(
		IQueryBuilder &$qb, string $recipient, bool $asAuthor = false, array $type = []
	) {
		$qb->andWhere($this->exprLimitToRecipient($qb, $recipient, $asAuthor, $type));
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $recipient
	 * @param bool $asAuthor
	 * @param array $type
	 *
	 * @return ICompositeExpression
	 */
	protected function exprLimitToRecipient(
		IQueryBuilder &$qb, string $recipient, bool $asAuthor = false, array $type = []
	): ICompositeExpression {

		$expr = $qb->expr();
		$limit = $expr->orX();

		if ($asAuthor === true) {
			$func = $qb->func();
			$limit->add(
				$expr->eq(
					$func->lower('attributed_to'),
					$func->lower($qb->createNamedParameter($recipient))
				)
			);
		}

		if ($type === []) {
			$type = ['to', 'cc', 'bcc'];
		}

		$this->addLimitToRecipient($qb, $limit, $type, $recipient);

		return $limit;
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param ICompositeExpression $limit
	 * @param array $type
	 * @param string $to
	 */
	private function addLimitToRecipient(
		IQueryBuilder $qb, ICompositeExpression &$limit, array $type, string $to
	) {

		$expr = $qb->expr();
		if (in_array('to', $type)) {
			$limit->add($expr->eq('to', $qb->createNamedParameter($to)));
			$limit->add($this->exprValueWithinJsonFormat($qb, 'to_array', $to));
		}

		if (in_array('cc', $type)) {
			$limit->add($this->exprValueWithinJsonFormat($qb, 'cc', $to));
		}

		if (in_array('bcc', $type)) {
			$limit->add($this->exprValueWithinJsonFormat($qb, 'bcc', $to));
		}
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $recipient
	 */
	protected function filterRecipient(IQueryBuilder &$qb, string $recipient) {

		$expr = $qb->expr();
		$filter = $expr->andX();

		$filter->add($expr->neq('to', $qb->createNamedParameter($recipient)));
		$filter->add($this->exprValueNotWithinJsonFormat($qb, 'to_array', $recipient));
		$filter->add($this->exprValueNotWithinJsonFormat($qb, 'cc', $recipient));
		$filter->add($this->exprValueNotWithinJsonFormat($qb, 'bcc', $recipient));

		$qb->andWhere($filter);
	}


	/**
	 * @param array $data
	 * @param string $as
	 *
	 * @return Stream
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	protected function parseStreamSelectSql(array $data, string $as = Stream::TYPE): Stream {
		if ($as === Stream::TYPE) {
			$as = $this->get('type', $data, Stream::TYPE);
		}

		/** @var Stream $item */
		$item = AP::$activityPub->getItemFromType($as);
		$item->importFromDatabase($data);

		$instances = json_decode($this->get('instances', $data, '[]'), true);
		if (is_array($instances)) {
			foreach ($instances as $instance) {
				$instancePath = new InstancePath();
				$instancePath->import($instance);
				$item->addInstancePath($instancePath);
			}
		}

		try {
			$actor = $this->parseCacheActorsLeftJoin($data);
			$item->setCompleteDetails(true);
			$item->setActor($actor);
		} catch (InvalidResourceException $e) {
		}

		$action = $this->parseStreamActionsLeftJoin($data);
		if ($item->hasCache()) {
			$cache = $item->getCache();
			try {
				$cachedItem = $cache->getItem($action->getStreamId());
				$cachedObject = $cachedItem->getObject();
				$cachedObject['action'] = $action;
				$cachedItem->setContent(json_encode($cachedObject));
				$cache->updateItem($cachedItem, false);
			} catch (CacheItemNotFoundException $e) {
			}
		}

		$item->setAction($action);
		if ($item->getType() === Announce::TYPE) {
			$item->setAttributedTo($this->get('following_actor_id', $data, ''));
		}

		return $item;
	}

}

