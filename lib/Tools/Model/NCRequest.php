<?php

declare(strict_types=1);


/**
 * Some tools for myself.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2020, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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
