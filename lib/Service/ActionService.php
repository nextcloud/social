<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TStringTools;

class ActionService {
	use TStringTools;

	private const TRANSLATE = 'translate';
	private const FAVOURITE = 'favourite';
	private const UNFAVOURITE = 'unfavourite';
	private const REBLOG = 'reblog';
	private const UNREBLOG = 'unreblog';
	private const BOOKMARK = 'bookmark';
	private const UNBOOKMARK = 'unbookmark';
	private const MUTE = 'mute';
	private const UNMUTE = 'unmute';
	private const PIN = 'pin';
	private const UNPIN = 'unpin';

	private static array $availableStatusAction = [
		self::TRANSLATE,
		self::FAVOURITE,
		self::UNFAVOURITE,
		self::REBLOG,
		self::UNREBLOG,
		self::BOOKMARK,
		self::UNBOOKMARK,
		self::MUTE,
		self::UNMUTE,
		self::PIN,
		self::UNPIN
	];

	public function __construct(
		private StreamService $streamService,
		private BoostService $boostService,
		private LikeService $likeService,
		private StreamActionService $streamActionService,
		private PinService $pinService,
		private ActionsRequest $actionsRequest,
		private ConversationsRequest $conversationsRequest,
	) {
	}

	/**
	 * The accounts that favourited or boosted a post, newest first.
	 *
	 * Mastodon's `favourited_by` and `reblogged_by`, and the reason a tap on a
	 * favourite or boost count is not a dead end. The caller has already
	 * resolved the post through the visibility filter, so whoever may read the
	 * post may see who reacted to it — which is Mastodon's rule too.
	 *
	 * An action whose actor this instance never cached is left out rather than
	 * sent half-filled: a page of accounts with no handle and no avatar is one
	 * a client can do nothing with.
	 *
	 * @return Person[]
	 */
	public function reactedBy(Stream $post, string $type, int $limit, int $offset = 0): array {
		$accounts = [];
		foreach ($this->actionsRequest->getActionsOnObject($post->getId(), $type, $limit, $offset) as $action) {
			if (!$action->hasActor()) {
				continue;
			}

			$actor = $action->getActor();
			$actor->setExportFormat(ACore::FORMAT_LOCAL);
			$accounts[] = $actor;
		}

		return $accounts;
	}

	/**
	 * should return null
	 * will return Stream only with translate action
	 *
	 * @param int $nid
	 * @param string $action
	 *
	 * @return Stream|null
	 * @throws InvalidActionException
	 */
	/**
	 * Stops, or restarts, the notifications a thread produces for one account.
	 *
	 * Mastodon's conversation mute is about being *told*, not about seeing:
	 * the posts stay on the timelines and the notifications stop. The mute is
	 * recorded against the thread's root, so a reply that arrives tomorrow is
	 * covered by a mute taken today — which is the whole point of muting a
	 * conversation rather than a post.
	 */
	private function muteConversation(Person $actor, Stream $post, bool $muted): void {
		$this->conversationsRequest->setMuted(
			$actor->getId(),
			$this->conversationsRequest->rootOf($post->getId()),
			$muted
		);
	}

	public function action(Person $actor, int $nid, string $action): ?Stream {
		if (!in_array($action, self::$availableStatusAction)) {
			throw new InvalidActionException();
		}

		$post = $this->streamService->getStreamByNid($nid);

		switch ($action) {
			case self::TRANSLATE:
				return $this->translate($nid);
			case self::FAVOURITE:
				$this->favourite($actor, $post->getId());
				break;

			case self::UNFAVOURITE:
				$this->favourite($actor, $post->getId(), false);
				break;

			case self::REBLOG:
				$this->reblog($actor, $post->getId());
				break;

			case self::UNREBLOG:
				$this->reblog($actor, $post->getId(), false);
				break;

			case self::BOOKMARK:
				$this->bookmark($actor, $post->getId());
				break;

			case self::UNBOOKMARK:
				$this->bookmark($actor, $post->getId(), false);
				break;

			case self::PIN:
				return $this->pinService->pin($actor, $nid);
			case self::UNPIN:
				return $this->pinService->unpin($actor, $nid);
			case self::MUTE:
			case self::UNMUTE:
				$this->muteConversation($actor, $post, $action === self::MUTE);
				break;
		}

		return null;
	}

	/**
	 * TODO: returns a translated version of the Status
	 *
	 * @param int $nid
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	private function translate(int $nid): Stream {
		return $this->streamService->getStreamByNid($nid);
	}

	private function favourite(Person $actor, string $postId, bool $enabled = true): void {
		if ($enabled) {
			$this->likeService->create($actor, $postId);
		} else {
			$this->likeService->delete($actor, $postId);
		}
	}

	private function reblog(Person $actor, string $postId, bool $enabled = true): void {
		if ($enabled) {
			$this->boostService->create($actor, $postId);
		} else {
			$this->boostService->delete($actor, $postId);
		}
	}

	/**
	 * Bookmarks are a purely local, per-viewer flag (as on Mastodon) — nothing
	 * is federated.
	 */
	private function bookmark(Person $actor, string $postId, bool $enabled = true): void {
		$this->streamActionService->setActionBool(
			$actor->getId(), $postId, StreamAction::BOOKMARKED, $enabled
		);
	}
}
