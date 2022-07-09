<?php

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service;

use Doctrine\Common\Collections\Collection;
use OCA\Social\Entity\Account;
use OCA\Social\Entity\Follow;
use OCP\DB\ORM\IEntityManager;
use OCP\DB\ORM\IEntityRepository;
use OCP\IRequest;
use OCP\IUser;

class AccountFinder {
	private IEntityManager $entityManager;
	private IEntityRepository $repository;
	private IRequest $request;
	private ?Account $representative = null;

	public function __construct(IEntityManager $entityManager, IRequest $request) {
		$this->entityManager = $entityManager;
		$this->repository = $this->entityManager->getRepository(Account::class);
		$this->request = $request;
	}

	public function findRemote(string $userName, ?string $domain): ?Account {
		return $this->repository->findOneBy([
			'domain' => $domain,
			'userName' => $userName,
		]);
	}

	public function findLocal(string $userName): ?Account {
		return $this->findRemote($userName, null);
	}

	public function getAccountByNextcloudId(string $userId): ?Account {
		return $this->repository->findOneBy([
			'userId' => $userId,
		]);
	}

	public function getCurrentAccount(IUser $user): Account {
		$account = $this->getAccountByNextcloudId($user->getUID());
		if ($account) {
			return $account;
		}
		$account = Account::newLocal();
		$account->setUserName($user->getUID());
		$account->setUserId($user->getUID());
		$account->setName($user->getDisplayName());
		$account->generateKeys();
		$this->entityManager->persist($account);
		$this->entityManager->flush();
		return $account;
	}

	public function getRepresentative(): Account {
		if ($this->representative !== null) {
			return $this->representative;
		}
		$account = $this->repository->findOneBy([
			'userId' => '__self',
		]);
		if ($account) {
			$this->representative = $account;
			return $account;
		}
		$account = Account::newLocal();
		$account->setRepresentative()
			->setActorType(Account::TYPE_APPLICATION)
			->setUserName($this->request->getServerHost())
			->setUserId('__self')
			->setLocked(true)
			->generateKeys();
		$this->entityManager->persist($account);
		$this->entityManager->flush();
		$this->representative = $account;
		return $account;
	}

	/**
	 * @param Account $account
	 * @return array<Follow>
	 */
	public function getLocalFollowersOf(Account $account): array {
		return $this->entityManager->createQuery('SELECT f,a FROM \OCA\Social\Entity\Follow f LEFT JOIN f.account a WHERE f.targetAccount = :target')
			->setParameters(['target' => $account])->getResult();
	}
}
