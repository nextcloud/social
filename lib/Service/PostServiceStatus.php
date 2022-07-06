<?php

namespace OCA\Social\Service;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Status;
use OCP\DB\ORM\IEntityManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;

class PostServiceStatus {
	private ICache $idempotenceCache;
	private IConfig $config;
	private IEntityManager $entityManager;

	public function __construct(ICacheFactory $cacheFactory, IConfig $config, IEntityManager $entityManager) {
		$this->idempotenceCache = $cacheFactory->createDistributed('social.idempotence');
		$this->config = $config;
		$this->entityManager = $entityManager;
	}

	public function create(Account $account, array $options) {
		$status = new Status();
		$status->setText($options['text'] ?? '');
		$status->setSensitive(isset($options['spoilerText'])
			|| ($options['sensitive'] ?? $this->config->getUserValue($account->getUserId(), 'social', 'default_sensitivity', 'no')) === 'yes');
		$status->setAccount($account);
		$status->setLocal(true);

		if (isset($options['inReplyToId'])) {
			$status->setInReplyToId();
		}

		$visibility = $options['visibility'] ?? $this->config->get;
		if (!in_array($visibility, [Status::STATUS_DIRECT, Status::STATUS_PRIVATE, Status::STATUS_PUBLIC, Status::STATUS_UNLISTED])) {
			throw new ApiException('Invalid visibility');
		}

	}

	private function checkIdempotenceDuplicate(): void {
		if (!isset($options['idempotency'])) {
			return;
		}

		if ($this->idempotenceCache->get($options['idempotency']) !== null) {
			throw new ApiException('Same message already sent');
		}
	}
}
