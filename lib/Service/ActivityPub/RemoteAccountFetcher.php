<?php

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Service\ActivityPub;

use OCA\Social\Entity\Account;
use OCP\IRequest;

class RemoteAccountFetchOption {
	public bool $id = true;
	public ?string $prefetchedBody = null;
	public bool $breakOnRedirect = false;
	public bool $onlyKey = false;

	static public function default(): self {
		return new self();
	}
}

class RemoteAccountFetcher {
	private IRequest $request;
	private TagManager $tagManager;

	public function __construct(IRequest $request) {
		$this->request = $request;
		$this->tagManager = TagManager::getInstance();
	}

	public function fetch(?string $uri, RemoteAccountFetchOption $fetchOption): ?Account {
		if ($this->tagManager->isLocalUri($uri)) {
			return $this->tagManager->uriToResource($uri, Account::class);
		}

		if ($fetchOption->prefetchedBody !== null) {
			$json = json_decode($fetchOption->prefetchedBody);
		} else {
			$json = $this->fetchResource($uri, $fetchOption->id);
		}

		return null;
	}

	public function fetchResource() {

	}
}
