<?php

namespace OCA\Social\Service;

use OCA\Social\Entity\Account;
use OCP\DB\ORM\IEntityManager;

class AccountFinder {
	private IEntityManager $entityManager;

	public function __construct(IEntityManager $entityManager) {
		$this->entityManager = $entityManager;
	}

	public function findRemote(string $userName, ?string $domain): ?Account {
		return $this->entityManager->getRepository(Account::class)
			->findOneBy([
				'domain' => $domain,
				'userName' => $userName,
			]);
	}

	public function findLocal(string $userName): ?Account {
		return $this->findRemote($userName, null);
	}
}
