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
use DateTime;
use Doctrine\DBAL\Query\QueryBuilder;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Note;
use OCA\Social\Model\InstancePath;
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
			'sn.summary',
			'sn.published', 'sn.published_time', 'sn.attributed_to', 'sn.in_reply_to', 'sn.source',
			'sn.local', 'sn.instances', 'sn.creation'
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
	 * @param string $actorId
	 */
	protected function rightJoinFollowing(IQueryBuilder $qb, string $actorId = '') {
		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $qb->expr();
		$func = $qb->func();
		$pf = $this->defaultSelectAlias . '.';

		$orX = $expr->orX();
		if ($actorId !== '') {
			$orX->add($this->exprLimitToDBField($qb, 'attributed_to', $actorId, false));
		}

		// list of possible follow (to, to_array, cc, ...)
		$orXFollow = $expr->orX();
		$orXFollow->add($expr->eq($func->lower($pf . 'to'), $func->lower('f.follow_id')));
		$orXFollow->add(
			$expr->like(
				$func->lower($pf . 'to_array'), $func->concat(
				$qb->createNamedParameter('%"'),
				$func->concat($func->lower('f.follow_id'), $qb->createNamedParameter('"%'))
			)
			)
		);
		$orXFollow->add(
			$expr->like(
				$func->lower($pf . 'cc'), $func->concat(
				$qb->createNamedParameter('%"'),
				$func->concat($func->lower('f.follow_id'), $qb->createNamedParameter('"%'))
			)
			)
		);
		$orXFollow->add(
			$expr->like(
				$func->lower($pf . 'bcc'), $func->concat(
				$qb->createNamedParameter('%"'),
				$func->concat($func->lower('f.follow_id'), $qb->createNamedParameter('"%'))
			)
			)
		);

		// all possible follow, but linked by followers (actor_id) and accepted follow
		$andXFollow = $expr->andX();
		$andXFollow->add($orXFollow);
		$andXFollow->add($this->exprLimitToDBField($qb, 'actor_id', $actorId, false, 'f'));
		$andXFollow->add($this->exprLimitToDBFieldInt($qb, 'accepted', 1, 'f'));

		$orX->add($andXFollow);

		// TODO: SQLite does not support RIGHT JOIN
//		$qb->rightJoin(
		$qb->join(
			$this->defaultSelectAlias, CoreRequestBuilder::TABLE_SERVER_FOLLOWS, 'f',
			$orX
		);

	}


	/**
	 * @param array $data
	 *
	 * @return Note
	 */
	protected function parseNotesSelectSql($data): Note {
		$dTime = new DateTime($this->get('published_time', $data, 'yesterday'));

		// TODO - use $note->importFromDatabase() ?
		$note = new Note();
		$note->setId($data['id'])
			 ->setTo($data['to'])
			 ->setToArray(json_decode($data['to_array'], true))
			 ->setCcArray(json_decode($data['cc'], true))
			 ->setBccArray(json_decode($data['bcc']))
			 ->setLocal(($data['local'] === '1') ? true : false)
			 ->setPublished($data['published']);
		$note->setContent($data['content'])
			 ->setPublishedTime($dTime->getTimestamp())
			 ->setAttributedTo($data['attributed_to'])
			 ->setInReplyTo($data['in_reply_to']);

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

