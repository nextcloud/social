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


use Exception;
use OC\User\NoUserException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Post;

class PostService {


	/** @var NoteService */
	private $noteService;

	/** @var AccountService */
	private $actorService;

	/** @var ActivityService */
	private $activityService;

	/** @var MiscService */
	private $miscService;


	/**
	 * PostService constructor.
	 *
	 * @param NoteService $noteService
	 * @param AccountService $actorService
	 * @param ActivityService $activityService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NoteService $noteService, AccountService $actorService, ActivityService $activityService,
		MiscService $miscService
	) {
		$this->noteService = $noteService;
		$this->actorService = $actorService;
		$this->activityService = $activityService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Post $post
	 * @param ACore $activity
	 *
	 * @return string
	 * @throws ActorDoesNotExistException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 * @throws Exception
	 */
	public function createPost(Post $post, ACore &$activity = null): string {
		$note =
			$this->noteService->generateNote(
				$post->getUserId(), $post->getContent(), $post->getType()
			);

		$this->noteService->replyTo($note, $post->getReplyTo());
		$this->noteService->addRecipients($note, $post->getType(), $post->getTo());

		$actor = $this->actorService->getActorFromUserId($post->getUserId());

		return $this->activityService->createActivity($actor, $note, $activity);
	}


}

