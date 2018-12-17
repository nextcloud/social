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
use OCA\Social\Db\NotesRequest;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\NoteNotFoundException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;

class NoteService {


	/** @var NotesRequest */
	private $notesRequest;

	/** @var ActivityService */
	private $activityService;

	/** @var AccountService */
	private $accountService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var string */
	private $viewerId = '';


	/**
	 * NoteService constructor.
	 *
	 * @param NotesRequest $notesRequest
	 * @param ActivityService $activityService
	 * @param AccountService $accountService
	 * @param CacheActorService $cacheActorService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NotesRequest $notesRequest,
		ActivityService $activityService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		ConfigService $configService,
		MiscService $miscService
	) {
		$this->notesRequest = $notesRequest;
		$this->activityService = $activityService;
		$this->accountService = $accountService;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $viewerId
	 */
	public function setViewerId(string $viewerId) {
		$this->viewerId = $viewerId;
		$this->notesRequest->setViewerId($viewerId);
	}

	public function getViewerId(): string {
		return $this->viewerId;
	}


	/**
	 * @param string $userId
	 * @param string $content
	 *
	 * @param string $type
	 *
	 * @return Note
	 * @throws ActorDoesNotExistException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 * @throws AccountAlreadyExistsException
	 * @throws UrlCloudException
	 */
	public function generateNote(string $userId, string $content, string $type) {
		$note = new Note();
		$actor = $this->accountService->getActorFromUserId($userId);

		$note->setId($this->configService->generateId('@' . $actor->getPreferredUsername()));
		$note->setPublished(date("c"));
		$note->setAttributedTo(
			$this->configService->getUrlSocial() . '@' . $actor->getPreferredUsername()
		);

		$this->setRecipient($note, $actor, $type);
		$note->setContent($content);
		$note->convertPublished();
		$note->setLocal(true);

		return $note;
	}


	/**
	 * @param Note $note
	 * @param Person $actor
	 * @param string $type
	 */
	private function setRecipient(Note $note, Person $actor, string $type) {
		switch ($type) {
			case Note::TYPE_UNLISTED:
				$note->setTo($actor->getFollowers());
				$note->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				$note->addCc(ActivityService::TO_PUBLIC);
				break;

			case Note::TYPE_FOLLOWERS:
				$note->setTo($actor->getFollowers());
				$note->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;

			case Note::TYPE_DIRECT:
				break;

			default:
				$note->setTo(ActivityService::TO_PUBLIC);
				$note->addCc($actor->getFollowers());
				$note->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;
		}
	}


	/**
	 * @param Note $note
	 * @param string $type
	 * @param string $account
	 */
	public function addRecipient(Note $note, string $type, string $account) {
		if ($account === '') {
			return;
		}

		try {
			$actor = $this->cacheActorService->getFromAccount($account);
		} catch (Exception $e) {
			return;
		}

		$instancePath = new InstancePath(
			$actor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM
		);
		if ($type === Note::TYPE_DIRECT) {
			$instancePath->setPriority(InstancePath::PRIORITY_HIGH);
			$note->addToArray($actor->getId());
		} else {
			$note->addCc($actor->getId());
		}

		$note->addTag(
			[
				'type' => 'Mention',
				'href' => $actor->getId()
			]
		);

		$note->addInstancePath($instancePath);
	}


	/**
	 * @param Note $note
	 * @param string $type
	 * @param array $accounts
	 */
	public function addRecipients(Note $note, string $type, array $accounts) {
		if ($accounts === []) {
			return;
		}

		foreach ($accounts as $account) {
			$this->addRecipient($note, $type, $account);
		}
	}


	/**
	 * @param Note $note
	 * @param string $replyTo
	 */
	public function replyTo(Note $note, string $replyTo) {
		if ($replyTo === '') {
			return;
		}

		$note->setInReplyTo($replyTo);
		// TODO - type can be NOT public !
		$note->addInstancePath(
			new InstancePath($replyTo, InstancePath::TYPE_PUBLIC, InstancePath::PRIORITY_HIGH)
		);
	}


	/**
	 * @param Note $note
	 *
	 * @throws Exception
	 */
	public function deleteLocalNote(Note $note) {
		if (!$note->isLocal()) {
			return;
		}

		$note->setActorId($note->getAttributedTo());
		$this->activityService->deleteActivity($note);
		$this->notesRequest->deleteNoteById($note->getId());
	}


	/**
	 * @param string $id
	 *
	 * @return Note
	 * @throws NoteNotFoundException
	 */
	public function getNoteById(string $id): Note {
		return $this->notesRequest->getNoteById($id);
	}


	/**
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamHome(Person $actor, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamHome($actor, $since, $limit);
	}


	/**
	 * @param string $actorId
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamAccount(string $actorId, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamAccount($actorId, $since, $limit);
	}


	/**
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamDirect(Person $actor, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamDirect($actor, $since, $limit);
	}


	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamLocalTimeline(int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamTimeline($since, $limit, true);
	}


	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamInternalTimeline(int $since = 0, int $limit = 5): array {
		// TODO - admin should be able to provide a list of 'friendly/internal' instance of ActivityPub
		return [];
	}


	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamGlobalTimeline(int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamTimeline($since, $limit, false);
	}


}

