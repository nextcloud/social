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

class AttachmentMetaDim implements JsonSerializable {
	private ?int $width = null;
	private ?int $height = null;
	private string $size = '';
	private ?float $aspect = null;
	private ?float $duration = null;
	private ?float $bitrate = null;
	private ?float $frameRate = null;

	public function __construct(array $dim) {
		$width = (int)$dim[0];
		$height = (int)$dim[1];

		if ($width > 0 && $height > 0) {
			$this->width = $width;
			$this->height = $height;
			$this->size = $width . 'x' . $height;
			$this->aspect = $width / $height;
		}
	}

	public function setWidth(int $width): self {
		$this->width = $width;

		return $this;
	}

	public function getWidth(): ?int {
		return $this->width;
	}

	public function setHeight(int $height): self {
		$this->height = $height;

		return $this;
	}

	public function getHeight(): int {
		return $this->height;
	}

	public function setSize(string $size): self {
		$this->size = $size;

		return $this;
	}

	public function getSize(): string {
		return $this->size;
	}

	public function setAspect(float $aspect): self {
		$this->aspect = $aspect;

		return $this;
	}

	public function getAspect(): ?float {
		return $this->aspect;
	}

	public function setDuration(float $duration): self {
		$this->duration = $duration;

		return $this;
	}

	public function getDuration(): ?float {
		return $this->duration;
	}

	public function setBitrate(float $bitrate): self {
		$this->bitrate = $bitrate;

		return $this;
	}

	public function getBitrate(): ?float {
		return $this->bitrate;
	}

	public function setFrameRate(float $frameRate): self {
		$this->frameRate = $frameRate;

		return $this;
	}

	public function getFrameRate(): ?float {
		return $this->frameRate;
	}

	public function jsonSerialize(): array {
		return array_filter(
			[
				'width' => $this->getWidth(),
				'height' => $this->getHeight(),
				'size' => $this->getSize(),
				'aspect' => $this->getAspect(),
				'duration' => $this->getDuration(),
				'bitrate' => $this->getBitrate(),
				'frame_rate' => $this->getFrameRate()
			]
		);
	}
}
