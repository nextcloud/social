<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Activity;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * An answer to a story on the wire: `Story:Reaction` or `Story:Reply`.
 *
 * Neither type is in the Activity Streams vocabulary; both are Pixelfed's own,
 * down to the colon in the name, and they are what its inbox switches on. A
 * server that does not know them ignores them, which is the right outcome — an
 * answer to something it never received is nothing it can place.
 *
 * The activity is flat: no nested object, the text in `content` and the story
 * named by `inReplyTo`. Pixelfed checks that the activity's id and its actor
 * are on the same host, that the story and the recipient are its own, and that
 * the sender follows the recipient, and drops the activity in silence
 * otherwise — so all four are met on the way out as well as checked on the way
 * in.
 */
abstract class StoryInteraction extends ACore implements JsonSerializable {
	/**
	 * The wire type, which every subclass replaces.
	 *
	 * Declared here because the constructor reads `static::TYPE`: without it
	 * the base class refers to a constant it does not have, which happens to
	 * work at runtime — nothing constructs the abstract — and is a hole in the
	 * type all the same.
	 */
	public const TYPE = '';

	/** The story this answers, by its address. */
	private string $storyId = '';
	private string $content = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(static::TYPE);
	}

	public function getStoryId(): string {
		return $this->storyId;
	}

	public function setStoryId(string $storyId): self {
		$this->storyId = $storyId;

		return $this;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function setContent(string $content): self {
		$this->content = $content;

		return $this;
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);

		$this->setStoryId($this->validate(ACore::AS_ID, 'inReplyTo', $data, ''));
		$this->setContent($this->get('content', $data, ''));
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'inReplyTo' => $this->getStoryId(),
				'content' => $this->getContent(),
			]
		);
	}
}
