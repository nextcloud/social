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
use Exception;
use OCP\DB\Exception as DBException;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Class StreamDestRequest
 *
 * @package OCA\Social\Db
 */
class StreamDestRequest extends StreamDestRequestBuilder {
	use TStringTools;

	private CacheActorService $cacheActorService;

	public function __construct(
		IDBConnection $connection, LoggerInterface $logger, IURLGenerator $urlGenerator, CacheActorService $cacheActorService,
		ConfigService $configService, MiscService $miscService
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService);

		$this->cacheActorService = $cacheActorService;
	}

	public function create(string $streamId, string $actorId, string $type, string $subType = '') {
		$qb = $this->getStreamDestInsertSql();

		$qb->setValue('stream_id', $qb->createNamedParameter($qb->prim($streamId)));
		$qb->setValue('actor_id', $qb->createNamedParameter($qb->prim($actorId)));
		$qb->setValue('type', $qb->createNamedParameter($type));
		$qb->setValue('subtype', $qb->createNamedParameter($subType));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
		}
	}

	public function generateStreamDest(Stream $stream): void {
		if ($this->generateStreamNotification($stream)) {
			return;
		}

		if ($this->generateStreamDirect($stream)) {
			return;
		}

		$this->generateStreamHome($stream);
	}

	private function generateStreamHome(Stream $stream): bool {
		$recipients =
			[
				'to' => array_merge($stream->getToAll(), [$stream->getAttributedTo()]),
				'cc' => array_merge($stream->getCcArray(), $stream->getBccArray())
			];

		foreach (array_keys($recipients) as $subtype) {
			foreach ($recipients[$subtype] as $actorId) {
				if ($actorId === '') {
					continue;
				}

				$this->create($stream->getId(), $actorId, 'recipient', $subtype);
			}
		}

		return true;
	}

	private function generateStreamDirect(Stream $stream): bool {
		try {
			$author = $this->cacheActorService->getFromId($stream->getAttributedTo());
		} catch (Exception $e) {
			return false;
		}

		$all = array_merge(
			$stream->getToAll(), [$stream->getAttributedTo()], $stream->getCcArray(), $stream->getBccArray()
		);

		foreach ($all as $item) {
			if ($item === Stream::CONTEXT_PUBLIC || $item === $author->getFollowers()) {
				return false;
			}
		}

		foreach ($all as $actorId) {
			if ($actorId === '') {
				continue;
			}

			$this->create($stream->getId(), $actorId, 'dm');
		}

		return true;
	}

	private function generateStreamNotification(Stream $stream): bool {
		if ($stream->getType() !== SocialAppNotification::TYPE) {
			return false;
		}

		foreach ($stream->getToAll() as $actorId) {
			if ($actorId === '') {
				continue;
			}

			$this->create($stream->getId(), $actorId, 'notif');
		}

		return true;
	}

	public function emptyStreamDest(): void {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_DEST);

		$qb->executeStatement();
	}
}
