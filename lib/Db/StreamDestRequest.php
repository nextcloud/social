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
use OCP\DB\QueryBuilder\IQueryBuilder;
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

	public function __construct(
		IDBConnection $connection,
		LoggerInterface $logger,
		IURLGenerator $urlGenerator,
		private CacheActorService $cacheActorService,
		ConfigService $configService,
		MiscService $miscService,
	) {
		parent::__construct($connection, $logger, $urlGenerator, $configService, $miscService);
	}

	/**
	 * A dest row is what puts a post in a timeline, so a failure here is a post
	 * that exists and is in nobody's timeline — permanently, and until now
	 * invisibly. The duplicate case is expected (the same recipient can appear
	 * in both `to` and `cc`) and stays quiet; anything else is logged.
	 */
	public function create(string $streamId, string $actorId, string $type, string $subType = '') {
		$qb = $this->getStreamDestInsertSql();

		$qb->setValue('stream_id', $qb->createNamedParameter($qb->prim($streamId)));
		$qb->setValue('actor_id', $qb->createNamedParameter($qb->prim($actorId)));
		$qb->setValue('type', $qb->createNamedParameter($type));
		$qb->setValue('subtype', $qb->createNamedParameter($subType));

		try {
			$qb->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return;
			}

			// Raised, not swallowed. A recipient row is what puts a post in a
			// timeline, so a post saved without one exists and is in nobody's
			// timeline — and the log line was the only trace of it. Its caller
			// writes these inside the transaction that stores the post, where
			// throwing rolls the whole save back and the post can be saved
			// again. A duplicate is still not an error: that is the line above.
			$this->logger->error('could not store the recipient of a stream', [
				'streamId' => $streamId,
				'actorId' => $actorId,
				'type' => $type,
				'subtype' => $subType,
				'exception' => $e,
			]);

			throw $e;
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
		$recipients
			= [
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
	public function getRelatedToActor(Person $actor, int $limit = 0, int $afterId = 0): array {
		$qb = $this->getStreamDestSelectSql();
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		$orX = $qb->expr()->orX(
			$qb->exprLimitToDBField('actor_id', $qb->prim($actor->getId())),
			$qb->exprLimitToDBField('actor_id', $qb->prim($actor->getFollowers())),
			$qb->exprLimitToDBField('actor_id', $qb->prim($actor->getFollowing()))
		);
		$qb->where($orX);

		// Keyset paging, not an offset. The caller rewrites and sometimes
		// deletes the posts behind these rows as it walks them, so the set
		// shrinks underneath an offset and every shift skips a row — which for
		// this caller means a post left addressed to an account that is gone.
		// An id the caller has already passed cannot come back.
		if ($afterId > 0) {
			$qb->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('id', 'asc');

		return $this->getStreamDestsFromRequest($qb);
	}

	/**
	 * @param string $actorId
	 */
	public function deleteRelatedToActor(string $actorId): void {
		$qb = $this->getStreamDestDeleteSql();
		// actor_id holds the prim already, so it is matched as it stands:
		// LOWER() over it only defeated the social_sd_at index
		$qb->limitToDBField('actor_id', $qb->prim($actorId));

		$qb->executeStatement();
	}

	/**
	 * @param string $actorId
	 */
	public function moveActor(string $actorId, string $newId): void {
		$qb = $this->getStreamDestUpdateSql();
		$qb->set('actor_id', $qb->createNamedParameter($qb->prim($newId)));
		$qb->limitToDBField('actor_id', $qb->prim($actorId));

		$qb->executeStatement();
	}
}
