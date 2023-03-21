<?php

declare(strict_types=1);

/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2023, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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
		StreamActionService $streamActionService
	) {
		$this->streamService = $streamService;
		$this->boostService = $boostService;
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
		$this->boostService->delete($actor, $postId);
//		$this->streamActionService->setActionBool($actor->getId(), $postId, StreamAction::LIKED, $enabled);
	}

	private function reblog(Person $actor, string $postId, bool $enabled = true): void {
		if ($enabled) {
			$this->boostService->create($actor, $postId);
		} else {
			$this->boostService->delete($actor, $postId);
		}
	}
}
