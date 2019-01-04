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
use Doctrine\DBAL\Query\QueryBuilder;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

class NotesRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return IQueryBuilder
	 */
	protected function getNotesInsertSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert(self::TABLE_SERVER_NOTES);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return IQueryBuilder
	 */
	protected function getNotesUpdateSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(self::TABLE_SERVER_NOTES);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function getNotesSelectSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'sn.id', 'sn.type', 'sn.to', 'sn.to_array', 'sn.cc', 'sn.bcc', 'sn.content',
			'sn.summary', 'sn.hashtags', 'sn.published', 'sn.published_time', 'sn.attributed_to',
			'sn.in_reply_to', 'sn.source', 'sn.local', 'sn.instances', 'sn.creation'
		)
		   ->from(self::TABLE_SERVER_NOTES, 'sn');

		$this->defaultSelectAlias = 'sn';

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
		   ->from(self::TABLE_SERVER_NOTES, 'sn');

		$this->defaultSelectAlias = 'sn';

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return IQueryBuilder
	 */
	protected function getNotesDeleteSql(): IQueryBuilder {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete(self::TABLE_SERVER_NOTES);

		return $qb;
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param Person $actor
	 */
	protected function joinFollowing(IQueryBuilder $qb, Person $actor) {
		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $qb->expr();
		$func = $qb->func();
		$pf = $this->defaultSelectAlias . '.';

		$on = $expr->orX();
		$on->add($this->exprLimitToRecipient($qb, $actor->getFollowers(), false));

		// list of possible recipient as a follower (to, to_array, cc, ...)
		$recipientFields = $expr->orX();
		$recipientFields->add($expr->eq($func->lower($pf . 'to'), $func->lower('f.follow_id')));
		$recipientFields->add($this->exprFieldWithinJsonFormat($qb, 'to_array', 'f.follow_id'));
		$recipientFields->add($this->exprFieldWithinJsonFormat($qb, 'cc', 'f.follow_id'));
		$recipientFields->add($this->exprFieldWithinJsonFormat($qb, 'bcc', 'f.follow_id'));

		// all possible follow, but linked by followers (actor_id) and accepted follow
		$crossFollows = $expr->andX();
		$crossFollows->add($recipientFields);
		$crossFollows->add($this->exprLimitToDBField($qb, 'actor_id', $actor->getId(), false, 'f'));
		$crossFollows->add($this->exprLimitToDBFieldInt($qb, 'accepted', 1, 'f'));
		$on->add($crossFollows);

		$qb->join($this->defaultSelectAlias, CoreRequestBuilder::TABLE_SERVER_FOLLOWS, 'f', $on);
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
	protected function filterToRecipient(IQueryBuilder &$qb, string $recipient) {

		$expr = $qb->expr();
		$filter = $expr->andX();

		$filter->add($expr->neq('to', $qb->createNamedParameter($recipient)));
		$filter->add($this->exprValueNotWithinJsonFormat($qb, 'to_array', $recipient));
		$filter->add($this->exprValueNotWithinJsonFormat($qb, 'cc', $recipient));
		$filter->add($this->exprValueNotWithinJsonFormat($qb, 'bcc', $recipient));

		$qb->andWhere($filter);
//		return $filter;
	}


	/**
	 * @param array $data
	 *
	 * @return Note
	 */
	protected function parseNotesSelectSql($data): Note {
		$note = new Note();
		$note->importFromDatabase($data);

		$instances = json_decode($data['instances'], true);
		if (is_array($instances)) {
			foreach ($instances as $instance) {
				$instancePath = new InstancePath();
				$instancePath->import($instance);
				$note->addInstancePath($instancePath);
			}
		}

		try {
			$actor = $this->parseCacheActorsLeftJoin($data);
			$note->setCompleteDetails(true);
			$note->setActor($actor);
		} catch (InvalidResourceException $e) {
		}

		return $note;
	}

}

