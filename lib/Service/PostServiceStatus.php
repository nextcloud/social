<?php

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service;

use OCA\Social\Entity\Account;
use OCA\Social\Entity\Status;
use OCA\Social\Service\Feed\PostDeliveryService;
use OCP\DB\ORM\IEntityManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;

class PostServiceStatus {
	private ICache $idempotenceCache;
	private IConfig $config;
	private IEntityManager $entityManager;
	private ProcessMentionsService $mentionsService;
	private PostDeliveryService $deliveryService;

	public function __construct(
		ICacheFactory $cacheFactory,
		IConfig $config,
		IEntityManager $entityManager,
		ProcessMentionsService $mentionsService,
		PostDeliveryService $deliveryService
	) {
		$this->idempotenceCache = $cacheFactory->createDistributed('social.idempotence');
		$this->config = $config;
		$this->entityManager = $entityManager;
		$this->mentionsService = $mentionsService;
		$this->deliveryService = $deliveryService;
	}

	/**
	 * @psalm-param array{?text: string, ?spoilerText: string, ?sensitive: bool, ?visibility: Status::STATUS_*} $options
	 */
	public function create(Account $account, array $options): void {
		$this->checkIdempotenceDuplicate($account, $options);

		$status = new Status();
		$status->setText($options['text'] ?? '');
		$status->setSensitive(isset($options['spoilerText'])
			|| ($options['sensitive'] ?? $this->config->getUserValue($account->getUserId(), 'social', 'default_sensitivity', 'no') === 'yes'));
		$status->setAccount($account);
		$status->setLocal(true);

		if (isset($options['inReplyToId'])) {
			$status->setInReplyToId($options['inReplyToId']);
		}

		$visibility = $options['visibility'] ?? $this->config->getUserValue($account->getUserId(), 'social', 'default_privacy', Status::STATUS_PUBLIC);
		if (!in_array($visibility, [Status::STATUS_DIRECT, Status::STATUS_PRIVATE, Status::STATUS_PUBLIC, Status::STATUS_UNLISTED])) {
			throw new ApiException('Invalid visibility');
		}

		// Add mentioned user to CC
		$this->mentionsService->run($status);

		// Save status
		$this->entityManager->persist($account);
		$this->entityManager->flush();

		$this->deliveryService->run($status);

		$this->updateIdempotency($account, $status);
	}

	private function idempotencyKey(Account $account, string $idempotency): string {
		return $account->getUserId() . '-' . $idempotency;
	}

	private function checkIdempotenceDuplicate(Account $account, array $options): void {
		if (!isset($options['idempotency'])) {
			return;
		}

		if ($this->idempotenceCache->get($this->idempotencyKey($account, $options['idempotency'])) !== null) {
			throw new ApiException('Same message already sent');
		}
	}

	private function updateIdempotency(Account $account, Status $status): void {
		if (!isset($options['idempotency'])) {
			return;
		}

		$this->idempotenceCache->set($this->idempotencyKey($account, $options['idempotency']), $status->getId(), 3600);
	}
}
