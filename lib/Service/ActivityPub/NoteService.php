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

namespace OCA\Social\Service\ActivityPub;


use Exception;
use OC\User\NoUserException;
use OCA\Social\Db\NotesRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Core;
use OCA\Social\Model\ActivityPub\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityPubService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\ICoreService;
use OCA\Social\Service\MiscService;

class NoteService implements ICoreService {


	/** @var NotesRequest */
	private $notesRequest;

	/** @var ActorService */
	private $actorService;

	/** @var CurlService */
	private $curlService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * NoteService constructor.
	 *
	 * @param NotesRequest $notesRequest
	 * @param ActorService $actorService
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NotesRequest $notesRequest, ActorService $actorService,
		CurlService $curlService, ConfigService $configService,
		MiscService $miscService
	) {
		$this->notesRequest = $notesRequest;
		$this->actorService = $actorService;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $userId
	 * @param string $content
	 *
	 * @return Note
	 * @throws ActorDoesNotExistException
	 * @throws NoUserException
	 */
	public function generateNote(string $userId, string $content) {
		$note = new Note();
		$actor = $this->actorService->getActorFromUserId($userId);

		$note->setId($this->configService->generateId('@' . $actor->getPreferredUsername()));
		$note->setPublished(date("c"));
		$note->setAttributedTo(
			$this->configService->getRoot() . '@' . $actor->getPreferredUsername()
		);
		$note->setTo(ActivityPubService::TO_PUBLIC);
		$note->setContent($content);

		$note->saveAs($this);

		return $note;
	}


	/**
	 * @param Note $note
	 * @param string $to
	 * @param int $type
	 */
	public function assignTo(Note $note, string $to, int $type) {
		$note->addToArray($to);
		$note->addTag(
			[
				'type' => 'Mention',
				'href' => $to
			]
		);

		$note->addInstancePath(new InstancePath($to, $type));
	}


	/**
	 * @param Note $note
	 * @param string $replyTo
	 */
	public function replyTo(Note $note, string $replyTo) {
		$note->setInReplyTo($replyTo);
		$note->addInstancePath(new InstancePath($replyTo));
	}


	/**
	 * This method is called when saving the Note object
	 *
	 * @param Core $note
	 *
	 * @throws Exception
	 */
	public function save(Core $note) {
		/** @var Note $note */
		$this->notesRequest->create($note);
	}

}
