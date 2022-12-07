<?php

declare(strict_types=1);

/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2022, Maxence Lange <maxence@artificial-owl.com>
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

namespace OCA\Social\Model;

use JsonSerializable;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

class StreamDest implements IQueryRow, JsonSerializable {
	use TArrayTools;

	private string $streamId = '';
	private string $actorId = '';
	private string $type = '';
	private string $subtype = '';

	public function __construct() {
	}

	public function setStreamId(string $streamId): self {
		$this->streamId = $streamId;

		return $this;
	}

	public function getStreamId(): string {
		return $this->streamId;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setType(string $type): self {
		$this->type = $type;

		return $this;
	}

	public function getType(): string {
		return $this->type;
	}

	public function setSubtype(string $subtype): self {
		$this->subtype = $subtype;

		return $this;
	}

	public function getSubtype(): string {
		return $this->subtype;
	}

	public function importFromDatabase(array $data): void {
		$this->setStreamId($this->get('stream_id', $data));
		$this->setActorId($this->get('actor_id', $data));
		$this->setType($this->get('type', $data));
		$this->setSubtype($this->get('subtype', $data));
	}

	public function jsonSerialize(): array {
		return [
			'streamId' => $this->getStreamId(),
			'actorId' => $this->getActorId(),
			'type' => $this->getType(),
			'subtype' => $this->getSubtype(),
		];
	}
}
