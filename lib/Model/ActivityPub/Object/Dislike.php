<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Tools\IQueryRow;

/**
 * `Dislike`, which PeerTube sends and Mastodon has never had.
 *
 * Every PeerTube video carries a like count and a dislike count, and both
 * federate. Arriving here the second one was an activity with no model at all:
 * logged as an unknown type and dropped, so a video whose dislikes an author
 * cared about showed none of them however many it had.
 *
 * Stored the way a `Like` is — a row in `social_action` keyed by (actor,
 * object, type) — because it is the same kind of fact about the same kind of
 * pair, and counted onto the post as `dislikes`.
 *
 * **It never notifies.** A like tells an author somebody liked them; a dislike
 * arriving as a notification would be a way to needle somebody, one activity at
 * a time, from anywhere.
 */
class Dislike extends ACore implements JsonSerializable, IQueryRow {
	public const TYPE = 'Dislike';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(parent::jsonSerialize(), ['object' => $this->getObjectId()]);
	}
}
