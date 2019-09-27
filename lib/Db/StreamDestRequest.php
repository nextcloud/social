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


use daita\MySmallPhpTools\Traits\TStringTools;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\Social\Model\ActivityPub\Stream;


/**
 * Class StreamDestRequest
 *
 * @package OCA\Social\Db
 */
class StreamDestRequest extends StreamDestRequestBuilder {


	use TStringTools;


	/**
	 * @return int
	 */
	public function countStreamDest(): int {
		$qb = $this->countStreamDestSelectSql();

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		return $this->getInt('count', $data, 0);
	}


	/**
	 * @param Stream $stream
	 */
	public function generateStreamDest(Stream $stream) {
		$recipients = array_merge(
			$stream->getToAll(), $stream->getCcArray(), $stream->getBccArray(),
			[$stream->getAttributedTo()]
		);

		$streamId = $this->prim($stream->getId());
		foreach ($recipients as $actorId) {
			if ($actorId === '') {
				continue;
			}

			$qb = $this->getStreamDestInsertSql();

			$qb->setValue('stream_id', $qb->createNamedParameter($streamId));
			$qb->setValue('actor_id', $qb->createNamedParameter($this->prim($actorId)));
			$qb->setValue('type', $qb->createNamedParameter('recipient'));
			try {
				$qb->execute();
			} catch (UniqueConstraintViolationException $e) {
				\OC::$server->getLogger()
							->log(3, 'Social - Duplicate recipient on Stream ' . json_encode($stream));
			}

		}
	}


	/**
	 *
	 */
	public function generateRandomDest() {
		$qb = $this->getStreamDestInsertSql();

		$qb->setValue('actor_id', $qb->createNamedParameter($this->uuid()));
		$qb->setValue('stream_id', $qb->createNamedParameter($this->uuid()));
		$qb->setValue('type', $qb->createNamedParameter('unk'));

		$qb->execute();
	}

}

