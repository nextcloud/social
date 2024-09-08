<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tools\Traits\TStringTools;

class ActionService {
	use TStringTools;

	private StreamService $streamService;
	private BoostService $boostService;
	private LikeService $likeService;
	private StreamActionService $streamActionService;

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
		StreamService $streamService,
		BoostService $boostService,
		LikeService $likeService,
		StreamActionService $streamActionService
	) {
		$this->streamService = $streamService;
		$this->boostService = $boostService;
		$this->likeService = $likeService;
		$this->streamActionService = $streamActionService;
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
}
