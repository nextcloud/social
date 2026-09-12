<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\IQueryRow;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * An album an account curates out of its own posts.
 *
 * Pixelfed's organising feature, and the one an account arriving from there
 * notices is missing the first time it looks at its own profile. It is neither a
 * timeline nor a list: the posts are the owner's own, the order is chosen rather
 * than chronological, and the album has a title of its own.
 *
 * `id` is an integer in the row and a string in the JSON, like every other id a
 * client is handed here.
 *
 * `ownerId` is not part of the entity a client sees. It is carried so the row a
 * request names can be checked against the account that asked, as a SQL
 * predicate rather than a comparison made after the row was read -- see
 * CollectionsRequest.
 */
class Collection implements IQueryRow, JsonSerializable {
	use TArrayTools;

	/** Anyone, including a signed-out visitor. */
	public const VISIBILITY_PUBLIC = Stream::TYPE_PUBLIC;
	/** The owner's followers, and the owner. */
	public const VISIBILITY_FOLLOWERS = Stream::TYPE_FOLLOWERS;

	/**
	 * @var string[] the two that mean anything for an album. A direct
	 *               collection would have nobody to be direct to.
	 */
	public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_FOLLOWERS];

	public const DEFAULT_VISIBILITY = self::VISIBILITY_PUBLIC;

	/** What Mastodon's own title column admits. */
	public const TITLE_MAX = 255;

	private int $id = 0;
	private string $ownerId = '';
	private string $title = '';
	private string $description = '';
	private string $visibility = self::DEFAULT_VISIBILITY;
	private int $creation = 0;
	private int $updated = 0;
	private int $size = 0;

	/** @var Stream[] the first few, for a cover; not every item */
	private array $preview = [];

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getOwnerId(): string {
		return $this->ownerId;
	}

	public function setOwnerId(string $ownerId): self {
		$this->ownerId = $ownerId;

		return $this;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function setTitle(string $title): self {
		$this->title = mb_substr(trim($title), 0, self::TITLE_MAX);

		return $this;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setDescription(string $description): self {
		$this->description = $description;

		return $this;
	}

	public function getVisibility(): string {
		return $this->visibility;
	}

	/** Anything that is not one of the two admitted values means public. */
	public function setVisibility(string $visibility): self {
		$this->visibility = in_array($visibility, self::VISIBILITIES, true)
			? $visibility
			: self::DEFAULT_VISIBILITY;

		return $this;
	}

	public function isPublic(): bool {
		return $this->visibility === self::VISIBILITY_PUBLIC;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	public function getUpdated(): int {
		return $this->updated;
	}

	public function setUpdated(int $updated): self {
		$this->updated = $updated;

		return $this;
	}

	public function getSize(): int {
		return $this->size;
	}

	public function setSize(int $size): self {
		$this->size = $size;

		return $this;
	}

	/** @return Stream[] */
	public function getPreview(): array {
		return $this->preview;
	}

	/** @param Stream[] $preview */
	public function setPreview(array $preview): self {
		$this->preview = $preview;

		return $this;
	}

	#[\Override]
	public function importFromDatabase(array $data): void {
		$this->setId($this->getInt('id', $data));
		$this->setOwnerId($this->get('actor_id', $data));
		$this->setTitle($this->get('title', $data));
		$this->setDescription($this->get('description', $data));
		$this->setVisibility($this->get('visibility', $data, self::DEFAULT_VISIBILITY));
		$creation = $this->get('creation', $data);
		$this->setCreation(($creation === '') ? 0 : (int)strtotime($creation));
		$updated = $this->get('updated', $data);
		$this->setUpdated(($updated === '') ? 0 : (int)strtotime($updated));
		// present only when the read asked for it
		$this->setSize($this->getInt('size', $data));
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->getId(),
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
			'visibility' => $this->getVisibility(),
			'created_at' => date('c', $this->getCreation()),
			'updated_at' => date('c', ($this->getUpdated() === 0) ? $this->getCreation() : $this->getUpdated()),
			'size' => $this->getSize(),
			// exported in the client format, as every other post a client is
			// handed by this API is
			'posts' => array_map(static fn (Stream $post): array => $post->jsonSerialize(), $this->getPreview()),
		];
	}
}
