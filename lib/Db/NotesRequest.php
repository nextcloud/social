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


use OCA\Social\Model\ActivityPub\Note;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class NotesRequest extends NotesRequestBuilder {


	/**
	 * NotesRequest constructor.
	 *
	 * @param IDBConnection $connection
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IDBConnection $connection, ConfigService $configService, MiscService $miscService
	) {
		parent::__construct($connection, $configService, $miscService);
	}


	/**
	 * Insert a new Note in the database.
	 *
	 * @param Note $note
	 *
	 * @return int
	 * @throws \Exception
	 */
	public function save(Note $note): int {
		try {
			$qb = $this->getNotesInsertSql();
			$qb->setValue('id', $qb->createNamedParameter($note->getId()))
			   ->setValue('to', $qb->createNamedParameter($note->getTo()))
			   ->setValue(
				   'to_array', $qb->createNamedParameter(
				   json_encode($note->getToArray(), JSON_UNESCAPED_SLASHES)
			   )
			   )
			   ->setValue(
				   'cc', $qb->createNamedParameter(
				   json_encode($note->getCcArray(), JSON_UNESCAPED_SLASHES)
			   )
			   )
			   ->setValue(
				   'bcc', $qb->createNamedParameter(
				   json_encode($note->getBccArray()), JSON_UNESCAPED_SLASHES
			   )
			   )
			   ->setValue('content', $qb->createNamedParameter($note->getContent()))
			   ->setValue('summary', $qb->createNamedParameter($note->getSummary()))
			   ->setValue('published', $qb->createNamedParameter($note->getPublished()))
			   ->setValue('attributed_to', $qb->createNamedParameter($note->getAttributedTo()))
			   ->setValue('in_reply_to', $qb->createNamedParameter($note->getInReplyTo()))
			   ->setValue('source', $qb->createNamedParameter($note->getSource()))
			   ->setValue(
				   'creation',
				   $qb->createNamedParameter(new \DateTime('now'), IQueryBuilder::PARAM_DATE)
			   );
			$qb->execute();

			return $qb->getLastInsertId();
		} catch (\Exception $e) {
			throw $e;
		}
	}


	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return array
	 */
	public function getPublicNotes(int $since = 0, int $limit = 5): array {
		$qb = $this->getNotesSelectSql();
		$this->limitToRecipient($qb, ActivityService::TO_PUBLIC);
		$this->limitPaginate($qb, $since, $limit);
		$this->leftJoinCacheActors($qb, 'attributed_to');

		$notes = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$notes[] = $this->parseNotesSelectSql($data);
		}
		$cursor->closeCursor();

		return $notes;
	}

	/**
	 * @param string $actorId
	 *
	 * @return array
	 */
	public function getNotesForActorId(string $actorId): array {
		$qb = $this->getNotesSelectSql();
		$this->limitToRecipient($qb, $actorId);
		$this->leftJoinCacheActors($qb, 'attributed_to');

		$notes = [];
		$cursor = $qb->execute();
		while ($data = $cursor->fetch()) {
			$notes[] = $this->parseNotesSelectSql($data);
		}
		$cursor->closeCursor();

		return $notes;
	}


}
