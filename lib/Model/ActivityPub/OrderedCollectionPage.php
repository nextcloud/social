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

	/**
	 * One page of a collection: the items, a back-reference to the collection
	 * they belong to, and the neighbours a consumer walks.
	 *
	 * `next` is present exactly when the page came back full — the only thing
	 * that can be known here without counting the whole collection again — so a
	 * consumer stops at the first page that is not.
	 *
	 * @param string[]|array[] $items
	 */
	public static function of(string $partOf, string $pageUrl, int $page, array $items): self {
		$collectionPage = new self();
		$collectionPage->setId($pageUrl . '?page=' . $page);
		$collectionPage->setPartOf($partOf);
		$collectionPage->setOrderedItems($items);

		if (count($items) === OrderedCollection::PAGE_SIZE) {
			$collectionPage->setNext($pageUrl . '?page=' . ($page + 1));
		}
		if ($page > 1) {
			$collectionPage->setPrev($pageUrl . '?page=' . ($page - 1));
		}

		return $collectionPage;
	}

	/**
	 * The page number a `page` query parameter asks for, or 0 when it asks for
	 * none. `?page=true` is Mastodon's way of saying "the first one".
	 */
	public static function requestedPage(string $page): int {
		if ($page === 'true') {
			return 1;
		}

		return (ctype_digit($page) && (int)$page > 0) ? (int)$page : 0;
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

	#[\Override]
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
