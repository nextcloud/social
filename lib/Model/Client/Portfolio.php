<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * A page of somebody's work, to put on a CV.
 *
 * A profile is a feed: everything somebody posted, newest first, with the
 * follow button and the boosts and the replies around it. A portfolio is the
 * opposite — a title, a sentence, a chosen set of pictures, and nothing else.
 * One per account, off until its owner turns it on, and readable signed out,
 * which is the whole point of it.
 */
class Portfolio implements IQueryRow, JsonSerializable {
	use TArrayTools;

	public const LAYOUT_GRID = 'grid';
	public const LAYOUT_ROWS = 'rows';

	/** Where the pictures come from. */
	public const SOURCE_RECENT = 'recent';
	public const SOURCE_COLLECTION = 'collection';

	public const MAX_TITLE = 128;
	public const MAX_INTRO = 500;

	/** How many pictures a portfolio page shows. */
	public const MAX_POSTS = 60;

	private int $id = 0;
	private string $actorId = '';
	private bool $active = false;
	private string $title = '';
	private string $intro = '';
	private string $layout = self::LAYOUT_GRID;
	private string $source = self::SOURCE_RECENT;
	private int $collectionId = 0;
	private bool $showCaptions = true;
	private bool $showPlaces = true;
	private bool $showDates = false;
	private bool $showAvatar = true;
	private int $creation = 0;

	/** Filled in when the page is read. */
	private ?Person $author = null;
	private array $posts = [];

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	public function isActive(): bool {
		return $this->active;
	}

	public function setActive(bool $active): self {
		$this->active = $active;

		return $this;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function setTitle(string $title): self {
		$this->title = mb_substr(trim($title), 0, self::MAX_TITLE);

		return $this;
	}

	public function getIntro(): string {
		return $this->intro;
	}

	public function setIntro(string $intro): self {
		$this->intro = mb_substr(trim($intro), 0, self::MAX_INTRO);

		return $this;
	}

	public function getLayout(): string {
		return $this->layout;
	}

	public function setLayout(string $layout): self {
		$this->layout = ($layout === self::LAYOUT_ROWS) ? self::LAYOUT_ROWS : self::LAYOUT_GRID;

		return $this;
	}

	public function getSource(): string {
		return $this->source;
	}

	public function setSource(string $source): self {
		$this->source = ($source === self::SOURCE_COLLECTION)
			? self::SOURCE_COLLECTION : self::SOURCE_RECENT;

		return $this;
	}

	public function getCollectionId(): int {
		return $this->collectionId;
	}

	public function setCollectionId(int $collectionId): self {
		$this->collectionId = max(0, $collectionId);

		return $this;
	}

	public function showsCaptions(): bool {
		return $this->showCaptions;
	}

	public function setShowCaptions(bool $show): self {
		$this->showCaptions = $show;

		return $this;
	}

	public function showsPlaces(): bool {
		return $this->showPlaces;
	}

	public function setShowPlaces(bool $show): self {
		$this->showPlaces = $show;

		return $this;
	}

	public function showsDates(): bool {
		return $this->showDates;
	}

	public function setShowDates(bool $show): self {
		$this->showDates = $show;

		return $this;
	}

	public function showsAvatar(): bool {
		return $this->showAvatar;
	}

	public function setShowAvatar(bool $show): self {
		$this->showAvatar = $show;

		return $this;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getAuthor(): ?Person {
		return $this->author;
	}

	public function setAuthor(?Person $author): self {
		$this->author = $author;

		return $this;
	}

	public function getPosts(): array {
		return $this->posts;
	}

	public function setPosts(array $posts): self {
		$this->posts = $posts;

		return $this;
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		$this->setId($this->getInt('id', $data));
		$this->setActorId($this->get('actor_id', $data));
		$this->setActive($this->getBool('active', $data, false));
		$this->setTitle($this->get('title', $data));
		$this->setIntro($this->get('intro', $data));
		$this->setLayout($this->get('layout', $data, self::LAYOUT_GRID));
		$this->setSource($this->get('source', $data, self::SOURCE_RECENT));
		$this->setCollectionId($this->getInt('collection_id', $data));
		$this->setShowCaptions($this->getBool('show_captions', $data, true));
		$this->setShowPlaces($this->getBool('show_places', $data, true));
		$this->setShowDates($this->getBool('show_dates', $data, false));
		$this->setShowAvatar($this->getBool('show_avatar', $data, true));

		$creation = $this->get('creation', $data);
		$this->setCreation(($creation === '') ? 0 : (int)strtotime($creation));
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'account_id' => $this->getActorId(),
			'active' => $this->isActive(),
			'title' => $this->getTitle(),
			'intro' => $this->getIntro(),
			'layout' => $this->getLayout(),
			'source' => $this->getSource(),
			'collection_id' => (string)$this->getCollectionId(),
			'show_captions' => $this->showsCaptions(),
			'show_places' => $this->showsPlaces(),
			'show_dates' => $this->showsDates(),
			'show_avatar' => $this->showsAvatar(),
			'created_at' => date('c', $this->getCreation()),
			'account' => $this->getAuthor(),
			'posts' => $this->getPosts(),
		];
	}
}
