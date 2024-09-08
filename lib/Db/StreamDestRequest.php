<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use Exception;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamDest;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\DB\Exception as DBException;
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
		IDBConnection $connection, LoggerInterface $logger, IURLGenerator $urlGenerator,
		CacheActorService $cacheActorService,
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
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_STREAM_DEST);

		$qb->executeStatement();
	}


	/**
	 * @param string $actorId
	 *
	 * @return StreamDest[]
	 */
	public function getRelatedToActor(Person $actor): array {
		$qb = $this->getStreamDestSelectSql();
		$orX = $qb->expr()->orX();
		$orX->add($qb->exprLimitToDBField('actor_id', $qb->prim($actor->getId())));
		$orX->add($qb->exprLimitToDBField('actor_id', $qb->prim($actor->getFollowers())));
		$orX->add($qb->exprLimitToDBField('actor_id', $qb->prim($actor->getFollowing())));
		$qb->where($orX);

		return $this->getStreamDestsFromRequest($qb);
	}


	/**
	 * @param string $actorId
	 */
	public function deleteRelatedToActor(string $actorId): void {
		$qb = $this->getStreamDestDeleteSql();
		$qb->limitToActorId($qb->prim($actorId));

		$qb->executeStatement();
	}



	/**
	 * @param string $actorId
	 */
	public function moveActor(string $actorId, string $newId): void {
		$qb = $this->getStreamDestUpdateSql();
		$qb->set('actor_id', $qb->createNamedParameter($qb->prim($newId)));
		$qb->limitToActorId($qb->prim($actorId));

		$qb->executeStatement();
	}
}
