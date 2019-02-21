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
use OCA\Social\Exceptions\NoteNotFoundException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RequestContentException;
use OCA\Social\Exceptions\RequestNetworkException;
use OCA\Social\Exceptions\RequestResultSizeException;
use OCA\Social\Exceptions\RequestServerException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Post;

class PostService {


	/** @var NoteService */
	private $noteService;

	/** @var AccountService */
	private $accountService;

	/** @var ActivityService */
	private $activityService;

	/** @var MiscService */
	private $miscService;


	/**
	 * PostService constructor.
	 *
	 * @param NoteService $noteService
	 * @param AccountService $accountService
	 * @param ActivityService $activityService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NoteService $noteService, AccountService $accountService, ActivityService $activityService,
		MiscService $miscService
	) {
		$this->noteService = $noteService;
		$this->accountService = $accountService;
		$this->activityService = $activityService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Post $post
	 * @param ACore $activity
	 *
	 * @return string
	 * @throws SocialAppConfigException
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws NoteNotFoundException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws MalformedArrayException
	 */
	public function createPost(Post $post, ACore &$activity = null): string {
		$note =
			$this->noteService->generateNote(
				$post->getActor(), htmlentities($post->getContent(), ENT_QUOTES), $post->getType()
			);

		$this->noteService->replyTo($note, $post->getReplyTo());
		$this->noteService->addRecipients($note, $post->getType(), $post->getTo());
		$this->noteService->addHashtags($note, $post->getHashtags());

		$result = $this->activityService->createActivity($post->getActor(), $note, $activity);
		$this->accountService->cacheLocalActorDetailCount($post->getActor());

		return $result;
	}


}

