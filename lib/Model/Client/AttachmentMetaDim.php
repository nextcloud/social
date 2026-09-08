<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;

class AttachmentMetaDim implements JsonSerializable {
	use TArrayTools;

	private ?int $width = null;
	private ?int $height = null;
	private string $size = '';
	private ?float $aspect = null;
	private ?float $duration = null;
	private ?float $bitrate = null;
	private ?float $frameRate = null;

	public function __construct(array $dim = []) {
		if (sizeof($dim) !== 2) {
			return;
		}

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

	public function getHeight(): ?int {
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

	public function import(array $data): self {
		$this->setWidth($this->getInt('width', $data))
			->setHeight($this->getInt('height', $data))
			->setSize($this->get('size', $data))
			->setAspect($this->getFloat('aspect', $data))
			->setDuration($this->getInt('duration', $data))
			->setBitrate($this->getInt('bitrate', $data))
			->setFrameRate($this->getFloat('frame_rate', $data));

		return $this;
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
