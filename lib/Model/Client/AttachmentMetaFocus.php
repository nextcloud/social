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

namespace OCA\Social\Model\Client;

use JsonSerializable;

class AttachmentMetaFocus implements JsonSerializable {
	private ?float $x;
	private ?float $y;

	public function __construct(?float $x = null, ?float $y = null) {
		$this->x = $x;
		$this->y = $y;
	}

	public function setX(float $x): self {
		$this->x = $x;

		return $this;
	}

	public function getX(): ?float {
		return $this->x;
	}

	public function setY(float $y): self {
		$this->y = $y;

		return $this;
	}

	public function getY(): ?float {
		return $this->y;
	}

	public function jsonSerialize(): array {
		return array_filter(
			[
				'x' => $this->getX(),
				'y' => $this->getY()
			]
		);
	}
}
