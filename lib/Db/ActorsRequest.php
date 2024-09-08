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
		   ->setValue('public_key', $qb->createNamedParameter($actor->getPublicKey()))
		   ->setValue('private_key', $qb->createNamedParameter($actor->getPrivateKey()))
		   ->setValue(
		   	'creation',
		   	$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
		   );

		$qb->executeStatement();
	}

	public function update(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('avatar_version', $qb->createNamedParameter($actor->getAvatarVersion()));
		$this->limitToIdString($qb, $actor->getId());

		$qb->executeStatement();
	}

	public function refreshKeys(Person $actor): void {
		$qb = $this->getActorsUpdateSql();
		$qb->set('public_key', $qb->createNamedParameter($actor->getPublicKey()))
		   ->set('private_key', $qb->createNamedParameter($actor->getPrivateKey()));

		try {
			$qb->set(
				'creation',
				$qb->createNamedParameter(new DateTime('now'), IQueryBuilder::PARAM_DATE)
			);
		} catch (Exception $e) {
		}

		$this->limitToIdString($qb, $actor->getId());

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
		$this->limitToPreferredUsername($qb, $username);

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
		$qb->limitToIdString($id);

		$cursor = $qb->execute();
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
		$this->limitToUserId($qb, $userId);

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

		$qb->execute();
	}

	/**
	 * @param string $handle
	 */
	public function delete(string $handle): void {
		$qb = $this->getActorsDeleteSql();
		$qb->limitToPreferredUsername($handle);

		$qb->execute();
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
		$this->searchInPreferredUsername($qb, $search);

		$accounts = [];
		$cursor = $qb->executeQuery();
		while ($data = $cursor->fetch()) {
			$accounts[] = $this->parseActorsSelectSql($data);
		}
		$cursor->closeCursor();

		return $accounts;
	}
}
