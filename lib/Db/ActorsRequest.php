<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use DateTime;
use Exception;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ActorsRequest extends ActorsRequestBuilder {
	/**
	 * Create a new Person in the database.
	 *
	 * @throws SocialAppConfigException
	 */
	public function create(Person $actor): void {
		$actor->setId($this->configService->getSocialUrl() . '@' . $actor->getPreferredUsername());
		$qb = $this->getActorsInsertSql();

		$qb->setValue('id', $qb->createNamedParameter($actor->getId()))
			->setValue('id_prim', $qb->createNamedParameter($qb->prim($actor->getId())))
			->setValue('user_id', $qb->createNamedParameter($actor->getUserId()))
			->setValue('name', $qb->createNamedParameter($actor->getName()))
			->setValue('summary', $qb->createNamedParameter($actor->getSummary()))
			->setValue('avatar_version', $qb->createNamedParameter($actor->getAvatarVersion()))
			->setValue(
				'preferred_username', $qb->createNamedParameter($actor->getPreferredUsername())
			)
			->setValue('locked', $qb->createNamedParameter($actor->isLocked() ? 1 : 0))
			->setValue('discoverable', $qb->createNamedParameter($actor->isDiscoverable() ? 1 : 0))
			->setValue('indexable', $qb->createNamedParameter($actor->isIndexable() ? 1 : 0))
			->setValue('bot', $qb->createNamedParameter($actor->isBot() ? 1 : 0))
			->setValue('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			->setValue('private_key', $qb->createNamedParameter($this->keyCipher->seal($actor->getPrivateKey())))
			->setValue(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);

		$qb->executeStatement();
	}

	public function update(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('avatar_version', $qb->createNamedParameter($actor->getAvatarVersion()))
			->set('summary', $qb->createNamedParameter($actor->getSummary()))
			->set('name', $qb->createNamedParameter($actor->getName()));
		$this->limitToIdPrimString($qb, $actor->getId());

		$qb->executeStatement();
	}

	/**
	 * Stores changed profile metadata fields.
	 */
	public function updateFields(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('fields', $qb->createNamedParameter(json_encode($actor->getFields())));
		$this->limitToIdPrimString($qb, $actor->getId());

		$qb->executeStatement();
	}

	/**
	 * Stores the bio — the plain text an actor's `summary` is rendered from.
	 */
	public function updateSummary(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('summary', $qb->createNamedParameter($actor->getSummary()));
		$this->limitToIdPrimString($qb, $actor->getId());

		$qb->executeStatement();
	}

	/**
	 * Stores a changed locked flag (manuallyApprovesFollowers).
	 */
	public function updateLocked(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('locked', $qb->createNamedParameter($actor->isLocked() ? 1 : 0));
		$this->limitToIdPrimString($qb, $actor->getId());

		$qb->executeStatement();
	}

	/**
	 * Stores the directory flags: `discoverable` (may be listed in directories
	 * and suggestions), `indexable` (posts may be full-text indexed) and `bot`
	 * (an automated account, which the actor document publishes as a `Service`
	 * rather than a `Person`).
	 */
	public function updateFlags(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('discoverable', $qb->createNamedParameter($actor->isDiscoverable() ? 1 : 0))
			->set('indexable', $qb->createNamedParameter($actor->isIndexable() ? 1 : 0))
			->set('bot', $qb->createNamedParameter($actor->isBot() ? 1 : 0));
		$this->limitToIdPrimString($qb, $actor->getId());

		$qb->executeStatement();
	}

	/**
	 * Stores the `alsoKnownAs` list — the actor ids this one also answers to,
	 * which is what a remote server checks before it accepts a Move towards
	 * here.
	 */
	public function updateAlsoKnownAs(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('also_known_as', $qb->createNamedParameter(json_encode($actor->getAlsoKnownAs())));
		$this->limitToIdPrimString($qb, $actor->getId());

		$qb->executeStatement();
	}

	/**
	 * Stores where the actor moved to; empty means it has not.
	 */
	public function updateMovedTo(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('moved_to', $qb->createNamedParameter($actor->getMovedTo()));
		$this->limitToIdPrimString($qb, $actor->getId());

		$qb->executeStatement();
	}

	public function refreshKeys(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('public_key', $qb->createNamedParameter($actor->getPublicKey()))
			->set('private_key', $qb->createNamedParameter($this->keyCipher->seal($actor->getPrivateKey())));

		try {
			$qb->set(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$this->limitToIdPrimString($qb, $actor->getId());

		$qb->executeStatement();
	}

	/**
	 * Return Actor from database based on the username
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getFromUsername(string $username): Person {
		$qb = $this->getActorsSelectSql();
		$qb->limitToPreferredUsername($username);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ActorDoesNotExistException('Actor not found');
		}

		return $this->parseActorsSelectSql($data);
	}

	/**
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getFromId(string $id): Person {
		$qb = $this->getActorsSelectSql();
		$this->limitToIdPrimString($qb, $id);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ActorDoesNotExistException('Actor not found');
		}

		return $this->parseActorsSelectSql($data);
	}

	/**
	 * return Actor from database, based on the userId of the owner.
	 *
	 * @param string $userId
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getFromUserId(string $userId): Person {
		$qb = $this->getActorsSelectSql();
		$qb->limitToUserId($userId);

		$cursor = $qb->executeQuery();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === false) {
			throw new ActorDoesNotExistException('Actor not found');
		}

		return $this->parseActorsSelectSql($data);
	}

	public function setAsDeleted(string $handle): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set(
			'deleted',
			$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
		);
		$qb->limitToPreferredUsername($handle);

		$qb->executeStatement();
	}

	/**
	 * @param string $handle
	 */
	public function delete(string $handle): void {
		$qb = $this->getActorsDeleteSql();
		$qb->limitToPreferredUsername($handle);

		$qb->executeStatement();
	}

	/**
	 * @return Person[]
	 * @throws SocialAppConfigException
	 */
	public function getAll(): array {
		$qb = $this->getActorsSelectSql();

		$accounts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$accounts[] = $this->parseActorsSelectSql($data);
		}
		$cursor->closeCursor();

		return $accounts;
	}

	/**
	 * @return Person[]
	 * @throws SocialAppConfigException
	 */
	public function searchFromUsername(string $search): array {
		$qb = $this->getActorsSelectSql();
		$qb->searchInPreferredUsername($search);

		$accounts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$accounts[] = $this->parseActorsSelectSql($data);
		}
		$cursor->closeCursor();

		return $accounts;
	}
}
