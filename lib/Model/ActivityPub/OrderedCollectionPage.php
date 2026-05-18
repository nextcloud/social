<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub;

use JsonSerializable;

class OrderedCollectionPage extends ACore implements JsonSerializable {
	public const TYPE = 'OrderedCollectionPage';

	private string $partOf = '';
	private array $orderedItems = [];
	private string $next = '';
	private string $prev = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function getPartOf(): string {
		return $this->partOf;
	}

	public function setPartOf(string $partOf): self {
		$this->partOf = $partOf;

		return $this;
	}

	public function getOrderedItems(): array {
		return $this->orderedItems;
	}

	public function setOrderedItems(array $orderedItems): self {
		$this->orderedItems = $orderedItems;

		return $this;
	}

	public function getNext(): string {
		return $this->next;
	}

	public function setNext(string $next): self {
		$this->next = $next;

		return $this;
	}

	public function getPrev(): string {
		return $this->prev;
	}

	public function setPrev(string $prev): self {
		$this->prev = $prev;

		return $this;
	}

	public function jsonSerialize(): array {
		return array_filter(
			array_merge(
				parent::jsonSerialize(),
				[
					'partOf' => $this->getPartOf(),
					'orderedItems' => $this->getOrderedItems(),
					'next' => $this->getNext(),
					'prev' => $this->getPrev()
				]
			)
		);
	}
}
