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


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RequestContentException;
use OCA\Social\Exceptions\RequestNetworkException;
use OCA\Social\Exceptions\RequestResultNotJsonException;
use OCA\Social\Exceptions\RequestResultSizeException;
use OCA\Social\Exceptions\RequestServerException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Post;

class PostService {


	/** @var StreamService */
	private $streamService;

	/** @var AccountService */
	private $accountService;

	/** @var ActivityService */
	private $activityService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * PostService constructor.
	 *
	 * @param StreamService $streamService
	 * @param AccountService $accountService
	 * @param ActivityService $activityService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		StreamService $streamService, AccountService $accountService, ActivityService $activityService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->streamService = $streamService;
		$this->accountService = $accountService;
		$this->activityService = $activityService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Post $post
	 * @param string $token
	 *
	 * @return ACore
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws StreamNotFoundException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 */
	public function createPost(Post $post, &$token = ''): ACore {
		$note = new Note();
		$actor = $post->getActor();
		$this->streamService->assignItem($note, $actor, $post->getType());

		$note->setAttributedTo($actor->getId());
//		$this->configService->getSocialUrl() . '@' . $actor->getPreferredUsername()

		$note->setContent(htmlentities($post->getContent(), ENT_QUOTES));

		$this->streamService->replyTo($note, $post->getReplyTo());
		$this->streamService->addRecipients($note, $post->getType(), $post->getTo());
		$this->streamService->addHashtags($note, $post->getHashtags());

		$token = $this->activityService->createActivity($actor, $note, $activity);
		$this->accountService->cacheLocalActorDetailCount($actor);

		return $activity;
	}


}

