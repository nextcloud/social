<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub;

use JsonSerializable;

class OrderedCollection extends ACore implements JsonSerializable {
	public const TYPE = 'OrderedCollection';

	private int $totalItems = 0;
	private string $first = '';
	private string $last = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function getTotalItems(): int {
		return $this->totalItems;
	}

	public function setTotalItems(int $totalItems): self {
		$this->totalItems = $totalItems;

		return $this;
	}

	public function getFirst(): string {
		return $this->first;
	}

	public function setFirst(string $first): self {
		$this->first = $first;

		return $this;
	}

	public function getLast(): string {
		return $this->last;
	}

	public function setLast(string $last): self {
		$this->last = $last;

		return $this;
	}

	public function import(array $data): self {
		parent::import($data);
		$this->setFirst($this->validate(ACore::AS_USERNAME, 'first', $data, ''))
			->setLast($this->validate(ACore::AS_USERNAME, 'last', $data, ''))
			->setTotalItems($this->getInt('totalItems', $data));

		return $this;
	}

	public function jsonSerialize(): array {
		return array_filter(
			array_merge(
				parent::jsonSerialize(),
				[
					'totalItems' => $this->getTotalItems(),
					'first' => $this->getFirst(),
					'last' => $this->getLast()
				]
			)
		);
	}
}
