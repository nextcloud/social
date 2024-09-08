<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;

class AttachmentMetaFocus implements JsonSerializable {
	private float $x;
	private float $y;

	public function __construct(float $x = 0, float $y = 0) {
		$this->x = $x;
		$this->y = $y;
	}

	public function setX(float $x): self {
		$this->x = $x;

		return $this;
	}

	public function getX(): float {
		return $this->x;
	}

	public function setY(float $y): self {
		$this->y = $y;

		return $this;
	}

	public function getY(): float {
		return $this->y;
	}

	public function jsonSerialize(): array {
		return [
			'x' => $this->getX(),
			'y' => $this->getY()
		];
	}
}
