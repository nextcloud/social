<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Object;

use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Object\Playlist;
use OCA\Social\Service\PlaylistService;

/**
 * A channel's playlists, arriving.
 *
 * PeerTube users organise everything this way — a series, a course, "watch
 * later" — and a channel's playlists are half of what its page shows, so an
 * instance that ingested the videos and none of the playlists showed a
 * channel's work as an undifferentiated pile.
 *
 * Stored as a collection, because that is the same thing this app already has.
 * **Read-only**: it is somebody else's document, rebuilt from the wire whenever
 * it changes there, and nothing here adds to it or takes from it.
 */
class PlaylistInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private PlaylistService $playlistService,
	) {
	}

	/**
	 * @throws InvalidOriginException the playlist is not on the server that sent it
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		if (!$item instanceof Playlist) {
			return;
		}

		$item->checkOrigin($item->getId());

		$this->save($item);
	}

	/**
	 * A playlist inside a `Create` or an `Update`.
	 *
	 * Both do the same thing, because the document is the whole truth about
	 * the playlist either way: what arrives replaces what was there, which is
	 * the only way a video *removed* from a playlist can leave this copy of it.
	 */
	#[\Override]
	public function activity(ACore $activity, ACore $item): void {
		if (!$item instanceof Playlist) {
			return;
		}

		$type = $activity->getType();
		if ($type === Delete::TYPE) {
			// a playlist that is gone there is one nobody here can open; the
			// collection is left, because deleting somebody's shown collection
			// on the strength of one activity is a bigger act than this is
			return;
		}

		if ($type !== Update::TYPE && $type !== 'Create') {
			return;
		}

		$activity->checkOrigin($item->getId());
		$this->save($item);
	}

	#[\Override]
	public function save(ACore $item): void {
		if (!$item instanceof Playlist) {
			return;
		}

		$wire = $item->asWire();
		$owner = $wire['attributedTo'] ?? '';
		if (is_array($owner)) {
			$owner = $owner['id'] ?? ($owner[0]['id'] ?? ($owner[0] ?? ''));
		}

		$this->playlistService->receive($wire, is_string($owner) ? $owner : '');
	}
}
