<?php
declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Serializer;

use OCA\Social\Entity\Account;
use OCP\IRequest;
use OCP\IUserManager;

class AccountSerializer extends ActivityPubSerializer {
	private IRequest $request;
	private IUserManager $userManager;

	public function __construct(IRequest $request, IUserManager $userManager) {
		$this->request = $request;
		$this->userManager = $userManager;
	}

	public function toJsonLd(object $account): array {
		assert($account instanceof Account && $account->isLocal());

		$user = $this->userManager->get($account->getUserId());

		$baseUrl = "https://" . $this->request->getServerHost() . '/';
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
