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
use OCA\Social\Tools\Traits\TArrayTools;

class AttachmentMeta implements JsonSerializable {
	use TArrayTools;

	private ?AttachmentMetaDim $original = null;
	private ?AttachmentMetaDim $small = null;
	private ?AttachmentMetaFocus $focus = null;

	private ?int $width = null;
	private ?int $height = null;
	private ?float $aspect = null;
	private string $size = '';
	private string $length = '';
	private ?float $duration = null;
	private ?float $fps = null;

	private string $audioEncode = '';
	private string $audioBitrate = '';
	private string $audioChannels = '';

	private string $description = '';
	private string $blurHash = '';

	public function setOriginal(AttachmentMetaDim $original): self {
		$this->original = $original;

		return $this;
	}

	public function getOriginal(): ?AttachmentMetaDim {
		return $this->original;
	}

	public function setSmall(AttachmentMetaDim $small): self {
		$this->small = $small;

		return $this;
	}

	public function getSmall(): ?AttachmentMetaDim {
		return $this->small;
	}

	public function setFocus(AttachmentMetaFocus $focus): self {
		$this->focus = $focus;

		return $this;
	}

	public function getFocus(): ?AttachmentMetaFocus {
		return $this->focus;
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

	public function setAspect(float $aspect): self {
		$this->aspect = $aspect;

		return $this;
	}

	public function getAspect(): ?float {
		return $this->aspect;
	}

	public function setSize(string $size): self {
		$this->size = $size;

		return $this;
	}

	public function getSize(): string {
		return $this->size;
	}

	public function setLength(string $length): self {
		$this->length = $length;

		return $this;
	}

	public function getLength(): string {
		return $this->length;
	}

	public function setDuration(float $duration): self {
		$this->duration = $duration;

		return $this;
	}

	public function getDuration(): ?float {
		return $this->duration;
	}

	public function setFps(float $fps): self {
		$this->fps = $fps;

		return $this;
	}

	public function getFps(): ?float {
		return $this->fps;
	}

	public function setAudioEncode(string $audioEncode): self {
		$this->audioEncode = $audioEncode;

		return $this;
	}

	public function getAudioEncode(): string {
		return $this->audioEncode;
	}

	public function setAudioBitrate(string $audioBitrate): self {
		$this->audioBitrate = $audioBitrate;

		return $this;
	}

	public function getAudioBitrate(): string {
		return $this->audioBitrate;
	}

	public function setAudioChannels(string $audioChannels): self {
		$this->audioChannels = $audioChannels;

		return $this;
	}

	public function getAudioChannels(): string {
		return $this->audioChannels;
	}

	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setBlurHash(string $blurHash): self {
		$this->blurHash = $blurHash;

		return $this;
	}

	public function getBlurHash(): ?string {
		return $this->blurHash;
	}

	public function jsonSerialize(): array {
		return array_filter(
			[
				'width' => $this->getWidth(),
				'height' => $this->getHeight(),
				'aspect' => $this->getAspect(),
				'size' => $this->getSize(),
				'length' => $this->getLength(),
				'duration' => $this->getDuration(),
				'fps' => $this->getFps(),
				'original' => $this->getOriginal(),
				'small' => $this->getSmall(),
				'focus' => $this->getFocus(),
				'audio_encode' => $this->getAudioEncode(),
				'audio_bitrate' => $this->getAudioBitrate(),
				'audio_channels' => $this->getAudioChannels(),
				'description' => $this->getDescription(),
				'blurhash' => $this->getBlurHash()
			]
		);
	}
}
