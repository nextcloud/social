<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Exceptions\StreamActionDoesNotExistException;
use OCA\Social\Model\StreamAction;

/**
 * Class StreamActionsRequest
 *
 * @package OCA\Social\Db
 */
class StreamActionsRequest extends StreamActionsRequestBuilder {
	/**
	 * Create a new Queue in the database.
	 */
	public function create(StreamAction $action): void {
		$qb = $this->getStreamActionInsertSql();

		$values = $action->getValues();
		$liked = $this->getBool(StreamAction::LIKED, $values, false);
		$boosted = $this->getBool(StreamAction::BOOSTED, $values, false);
		$replied = $this->getBool(StreamAction::REPLIED, $values, false);

		$qb->setValue('actor_id', $qb->createNamedParameter($action->getActorId()))
			->setValue('actor_id_prim', $qb->createNamedParameter($qb->prim($action->getActorId())))
			->setValue('stream_id', $qb->createNamedParameter($action->getStreamId()))
			->setValue('stream_id_prim', $qb->createNamedParameter($qb->prim($action->getStreamId())))
			->setValue('liked', $qb->createNamedParameter(($liked) ? 1 : 0))
			->setValue('boosted', $qb->createNamedParameter(($boosted) ? 1 : 0))
			->setValue('replied', $qb->createNamedParameter(($replied) ? 1 : 0));

		$qb->executeStatement();
	}


	public function update(StreamAction $action): int {
		$qb = $this->getStreamActionUpdateSql();

		// update entry/field in database, based only on affected action
		// to avoid race condition on 2 different actions
		foreach ($action->getAffected() as $entry) {
			$field = match ($entry) {
				StreamAction::LIKED => 'liked',
				StreamAction::BOOSTED => 'boosted',
				StreamAction::REPLIED => 'replied',
				default => ''
			};

			if ($field !== '') {
				$qb->set($field, $qb->createNamedParameter(($action->getValueBool($entry)) ? 1 : 0));
			}
		}

		$qb->limitToActorIdPrim($qb->prim($action->getActorId()));
		$qb->limitToStreamIdPrim($qb->prim($action->getStreamId()));

		return $qb->executeStatement();
	}


	/**
	 * @throws StreamActionDoesNotExistException
	 */
	public function getAction(string $actorId, string $streamId): StreamAction {
		$qb = $this->getStreamActionSelectSql();
		$this->limitToActorId($qb, $actorId);
		$this->limitToStreamId($qb, $streamId);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		if ($data === false) {
			throw new StreamActionDoesNotExistException();
		}
		$cursor->closeCursor();

		return $this->parseStreamActionsSelectSql($data);
	}

	public function delete(StreamAction $action): void {
		$qb = $this->getStreamActionDeleteSql();
		$this->limitToActorId($qb, $action->getActorId());
		$this->limitToStreamId($qb, $action->getStreamId());

		$qb->executeStatement();
	}
}
