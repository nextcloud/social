<?php

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service;

use Doctrine\Common\Collections\Collection;
use OCA\Social\Entity\Account;
use OCP\DB\ORM\IEntityManager;
use OCP\DB\ORM\IEntityRepository;
use OCP\IRequest;
use OCP\IUser;

class AccountFinder {
	private IEntityManager $entityManager;
	private IEntityRepository $repository;
	private IRequest $request;

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
		$account = $this->repository->findOneBy([
			'id' => Account::REPRESENTATIVE_ID,
		]);
		if ($account) {
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
		return $account;
	}

	/**
	 * @param Account $account
	 * @return array<Account>
	 */
	public function getLocalFollowersOf(Account $account): array {
		echo $this->entityManager->createQuery('SELECT a, f FROM \OCA\Social\Entity\Follow f LEFT JOIN f.account a WHERE f.targetAccount = :target')
			->setParameters(['target' => $account])->getSql() . ' ' . $account->getId() ;
		return $this->entityManager->createQuery('SELECT f FROM \OCA\Social\Entity\Follow f LEFT JOIN f.account a WHERE f.targetAccount = :target')
			->setParameters(['target' => $account])->getArrayResult();
	}
}
