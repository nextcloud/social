<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * PeerTube's `Playlist`: an ordered set of a channel's videos.
 *
 * The same thing this app calls a collection, which is why it is stored as one
 * rather than given a table of its own. What it carries beyond a name and a
 * description is `orderedItems`, a list of `PlaylistElement`s each naming a
 * video — and those are read out of the raw document rather than modelled,
 * because an element has no existence apart from the list it is in.
 */
class Playlist extends ACore implements JsonSerializable {
	public const TYPE = 'Playlist';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * The document as it arrived.
	 *
	 * `PlaylistService` reads the list out of this rather than off properties:
	 * the order, the positions and the ids are the whole of what a playlist is,
	 * and modelling each element would be a class per line of a list.
	 *
	 * @return array<string, mixed>
	 */
	public function asWire(): array {
		$source = json_decode($this->getSource(), true);

		return is_array($source) ? $source : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return parent::jsonSerialize();
	}
}
