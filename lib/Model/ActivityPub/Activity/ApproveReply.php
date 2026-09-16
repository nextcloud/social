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
 * FEP-5624's `ApproveReply`: the author of a post saying that a reply to it
 * may be shown.
 *
 * PeerTube ≥ 6.2 moderates comments. A video whose `commentsPolicy` is
 * "requires approval" takes a reply in and shows it to nobody until a human
 * has looked; when one does, the video's server sends this back to the server
 * the reply came from, naming the reply.
 *
 * Without it a reply written here looked posted, sat in a queue on the other
 * side, and either appeared a day later or never — with nothing anywhere to
 * say which.
 */
class ApproveReply extends ACore implements JsonSerializable {
	public const TYPE = 'ApproveReply';

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
