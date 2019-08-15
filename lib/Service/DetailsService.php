<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
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


use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamDetails;


/**
 * Class DetailsService
 *
 * @package OCA\Social\Service
 */
class DetailsService {

	/** @var StreamService */
	private $streamService;

	/** @var AccountService */
	private $accountService;

	/** @var FollowService */
	private $followService;

	/** @var CacheActorService */
	private $cacheActorService;


	/**
	 * DetailsService constructor.
	 *
	 * @param StreamService $streamService
	 * @param AccountService $accountService
	 * @param FollowService $followService
	 * @param CacheActorService $cacheActorService
	 */
	public function __construct(
		StreamService $streamService, AccountService $accountService,
		FollowService $followService, CacheActorService $cacheActorService
	) {
		$this->streamService = $streamService;
		$this->accountService = $accountService;
		$this->followService = $followService;
		$this->cacheActorService = $cacheActorService;
	}


	/**
	 * @param Stream $stream
	 *
	 * @return StreamDetails
	 * @throws SocialAppConfigException
	 */
	public function generateDetailsFromStream(Stream $stream): StreamDetails {
		$this->streamService->detectType($stream);

		$details = new StreamDetails($stream);
		$this->setStreamViewers($details);

		if ($stream->getTimeline() === Stream::TYPE_PUBLIC) {
			if ($stream->isLocal()) {
				$details->setPublic(true);
			} else {
				$details->setFederated(true);
			}
		}

		return $details;
	}


	/**
	 * @param StreamDetails $details
	 *
	 * @throws SocialAppConfigException
	 */
	private function setStreamViewers(StreamDetails $details) {
		$stream = $details->getStream();

		foreach ($stream->getRecipients(true) as $recipient) {
			try {
				$actor = $this->accountService->getFromId($recipient);
				$details->addDirectViewer($actor);
			} catch (ActorDoesNotExistException $e) {
			}

			$followers = $this->followService->getFollowersFromFollowId($recipient);
			foreach ($followers as $follower) {
				if (!$follower->hasActor()) {
					continue;
				}

				$actor = $follower->getActor();
				$details->addHomeViewer($actor);
			}
		}
	}

}

