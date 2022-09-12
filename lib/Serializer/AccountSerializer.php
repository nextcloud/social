<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Serializer;

use OCA\Social\Entity\Account;
use OCA\Social\InstanceUtils;
use OCP\IRequest;
use OCP\IUserManager;

class AccountSerializer extends ActivityPubSerializer {
	private IUserManager $userManager;
	private InstanceUtils $instanceUtils;

	public function __construct(IUserManager $userManager, InstanceUtils $instanceUtils) {
		$this->userManager = $userManager;
		$this->instanceUtils = $instanceUtils;
	}

	public function toJsonLd(object $account): array {
		assert($account instanceof Account && $account->isLocal());

		$user = $this->userManager->get($account->getUserId());

		$baseUrl = $this->instanceUtils->getLocalInstanceUrl() . '/';
		$baseUserUrl = $baseUrl . "/users/" . $account->getUserName() . '/';

		return array_merge($this->getContext(), [
			"id" => $baseUrl . $account->getUserName(),
			"type" => $account->getActorType(),
			"following" => $baseUserUrl . "following",
			"followers" => $baseUserUrl . "followers",
			"inbox" =>  $baseUserUrl . "inbox",
			"outbox" => $baseUserUrl . "outbox",
			"preferredUsername" => $account->getUserName(),
			"name" => $user->getDisplayName(),
			"publicKey" => [
				"id" => $baseUrl . $account->getUserName() . "#main-key",
				"owner" => $baseUrl . $account->getUserName(),
				"publicKeyPem" => $account->getPublicKey(),
			]
		]);
	}
}
