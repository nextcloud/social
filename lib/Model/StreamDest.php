<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
