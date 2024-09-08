<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Model;

use OCP\Http\Client\IClient;

/**
 * Class NCRequest
 *
 * @package OCA\Social\Tools\Model
 */
class NCRequest extends Request {
	private IClient $client;
	private array $clientOptions = [];
	private bool $localAddressAllowed = false;

	public function setClient(IClient $client): self {
		$this->client = $client;
		return $this;
	}

	public function getClient(): IClient {
		return $this->client;
	}

	public function getClientOptions(): array {
		return $this->clientOptions;
	}

	public function setClientOptions(array $clientOptions): self {
		$this->clientOptions = $clientOptions;

		return $this;
	}

	public function isLocalAddressAllowed(): bool {
		return $this->localAddressAllowed;
	}

	public function setLocalAddressAllowed(bool $allowed): self {
		$this->localAddressAllowed = $allowed;

		return $this;
	}

	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'clientOptions' => $this->getClientOptions(),
				'localAddressAllowed' => $this->isLocalAddressAllowed(),
			]
		);
	}
}
