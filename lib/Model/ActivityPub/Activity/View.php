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
 * "I watched your story."
 *
 * `View` is in the Activity Streams vocabulary and Pixelfed uses it for one
 * thing only: a receipt sent to the author of a story somebody has just
 * watched. That is why the object is not the story's address but a small
 * wrapper around it — `{"type": "Story", "object": "<address>"}` — which is
 * the shape Pixelfed's own `StoryViewDeliver` builds and the shape its inbox
 * insists on.
 *
 * It is addressed to the author alone. Who watched a story is told to the
 * person who posted it and to nobody else, which is the same rule this app
 * keeps for the local count.
 */
class View extends ACore implements JsonSerializable {
	public const TYPE = 'View';

	/** The address of the story that was watched. */
	private string $storyId = '';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function getStoryId(): string {
		return $this->storyId;
	}

	public function setStoryId(string $storyId): self {
		$this->storyId = $storyId;

		return $this;
	}

	/**
	 * @param array $data
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);

		// `object` is a wrapper, not an id: read out of the array it arrived
		// as rather than out of `objectId`, which a nested object leaves empty
		$object = $this->getArray('object', $data, []);
		if ($this->get('type', $object, '') === 'Story') {
			$this->setStoryId($this->validate(ACore::AS_ID, 'object', $object, ''));
		}
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'object' => [
					'type' => 'Story',
					'object' => $this->getStoryId(),
				],
			]
		);
	}
}
