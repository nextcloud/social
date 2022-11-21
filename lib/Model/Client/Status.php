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

use OCA\Social\Tools\Traits\TArrayTools;

class Status implements \JsonSerializable {
	use TArrayTools;

	private string $contentType = '';
	private bool $sensitive = false;
	private string $visibility = '';
	private string $spoilerText = '';
	private string $status = '';

	//"media_ids": [],

	public function __construct() {
	}


	/**
	 * @param string $contentType
	 *
	 * @return Status
	 */
	public function setContentType(string $contentType): self {
		$this->contentType = $contentType;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getContentType(): string {
		return $this->contentType;
	}


	/**
	 * @param bool $sensitive
	 *
	 * @return Status
	 */
	public function setSensitive(bool $sensitive): self {
		$this->sensitive = $sensitive;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isSensitive(): bool {
		return $this->sensitive;
	}


	/**
	 * @param string $visibility
	 *
	 * @return Status
	 */
	public function setVisibility(string $visibility): self {
		$this->visibility = $visibility;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getVisibility(): string {
		return $this->visibility;
	}


	/**
	 * @param string $spoilerText
	 *
	 * @return Status
	 */
	public function setSpoilerText(string $spoilerText): self {
		$this->spoilerText = $spoilerText;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSpoilerText(): string {
		return $this->spoilerText;
	}


	/**
	 * @param string $status
	 *
	 * @return Status
	 */
	public function setStatus(string $status): self {
		$this->status = $status;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}


	public function import(array $data): self {
		$this->setContentType($this->get('content_type', $data));
		$this->setSensitive($this->getBool('sensitive', $data));
		$this->setVisibility($this->get('visibility', $data));
		$this->setSpoilerText($this->get('spoiler_text', $data));
		$this->setStatus($this->get('status', $data));

		return $this;
	}

	public function jsonSerialize(): array {
		return [
			'contentType' => $this->getContentType(),
			'sensitive' => $this->isSensitive(),
			'visibility' => $this->getVisibility(),
			'spoilerText' => $this->getSpoilerText(),
			'status' => $this->getStatus()
		];
	}
}
