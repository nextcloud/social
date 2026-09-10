<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\StreamActionDoesNotExistException;
use OCA\Social\Model\StreamAction;
use OCP\DB\Exception as DBException;

/**
 * Class StreamActionsRequest
 *
 * @package OCA\Social\Db
 */
class StreamActionsRequest extends StreamActionsRequestBuilder {
	/**
	 * Stores the actions of one actor on one post.
	 *
	 * Insert first, and fall back to an update when the row is already there:
	 * the other way round (update, insert when nothing was updated) races with
	 * itself. Two concurrent likes of the same post both update nothing and
	 * both insert, and the loser gets an uncaught constraint violation — and on
	 * MySQL, where rowCount() counts *changed* rows, setting a flag to the
	 * value it already holds takes that same path even on its own.
	 */
	public function save(StreamAction $action): void {
		try {
			$this->create($action);

			return;
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}

		$this->update($action);
	}

	/**
	 * Create a new Queue in the database.
	 */
	public function create(StreamAction $action): void {
		$qb = $this->getStreamActionInsertSql();

		$values = $action->getValues();
		$liked = $this->getBool(StreamAction::LIKED, $values, false);
		$boosted = $this->getBool(StreamAction::BOOSTED, $values, false);
		$replied = $this->getBool(StreamAction::REPLIED, $values, false);
		$bookmarked = $this->getBool(StreamAction::BOOKMARKED, $values, false);

		$qb->setValue('actor_id', $qb->createNamedParameter($action->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($action->getActorId())))
			->setValue('stream_id', $qb->createNamedParameter($action->getStreamId()))
			->setValue('stream_id_prim', $qb->createNamedParameter($qb->prim($action->getStreamId())))
			->setValue('liked', $qb->createNamedParameter(($liked) ? 1 : 0))
			->setValue('boosted', $qb->createNamedParameter(($boosted) ? 1 : 0))
			->setValue('replied', $qb->createNamedParameter(($replied) ? 1 : 0))
			->setValue('bookmarked', $qb->createNamedParameter(($bookmarked) ? 1 : 0))
			->setValue('values', $qb->createNamedParameter(json_encode($this->nonFlagValues($action))));

		$qb->executeStatement();
	}

	public function update(StreamAction $action): int {
		$qb = $this->getStreamActionUpdateSql();

		// update entry/field in database, based only on affected action
		// to avoid race condition on 2 different actions
		$fields = 0;
		$valuesTouched = false;
		foreach ($action->getAffected() as $entry) {
			$field = match ($entry) {
				StreamAction::LIKED => 'liked',
				StreamAction::BOOSTED => 'boosted',
				StreamAction::REPLIED => 'replied',
				StreamAction::BOOKMARKED => 'bookmarked',
				default => ''
			};

			if ($field !== '') {
				$qb->set($field, $qb->createNamedParameter(($action->getValueBool($entry)) ? 1 : 0));
				$fields++;
			} elseif (!$valuesTouched) {
				// non-flag entries (poll votes) live in the values JSON
				$qb->set('values', $qb->createNamedParameter(json_encode($this->nonFlagValues($action))));
				$valuesTouched = true;
				$fields++;
			}
		}

		if ($fields === 0) {
			// setting a flag to the value it already has affects nothing — an
			// UPDATE without a SET clause is invalid SQL, not a no-op
			return 0;
		}

		$this->limitToPrim($qb, 'actor_id_prim', $action->getActorId());
		$this->limitToPrim($qb, 'stream_id_prim', $action->getStreamId());

		return $qb->executeStatement();
	}

	/**
	 * The values that are not one of the four flag columns: what the `values`
	 * JSON column stores.
	 */
	private function nonFlagValues(StreamAction $action): array {
		return array_diff_key($action->getValues(), [
			StreamAction::LIKED => true,
			StreamAction::BOOSTED => true,
			StreamAction::REPLIED => true,
			StreamAction::BOOKMARKED => true,
		]);
	}

	/**
	 * @throws StreamActionDoesNotExistException
	 */
	public function getAction(string $actorId, string $streamId): StreamAction {
		$qb = $this->getStreamActionSelectSql();
		// the (stream_id_prim, actor_id_prim) unique index, rather than LOWER()
		// over the two TEXT columns beside it — this runs on every like, boost,
		// bookmark and poll vote
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);
		$this->limitToPrim($qb, 'stream_id_prim', $streamId);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		if ($data === false) {
			throw new StreamActionDoesNotExistException();
		}
		$cursor->closeCursor();

		return $this->parseStreamActionsSelectSql($data);
	}

	/**
	 * Every action row of one actor: what they liked, boosted, bookmarked or
	 * voted on. Nothing else removed these when the account went.
	 */
	public function deleteByActor(string $actorId): void {
		$qb = $this->getStreamActionDeleteSql();
		$this->limitToPrim($qb, 'actor_id_prim', $actorId);

		$qb->executeStatement();
	}

	public function delete(StreamAction $action): void {
		$qb = $this->getStreamActionDeleteSql();
		$this->limitToPrim($qb, 'actor_id_prim', $action->getActorId());
		$this->limitToPrim($qb, 'stream_id_prim', $action->getStreamId());

		$qb->executeStatement();
	}
}
