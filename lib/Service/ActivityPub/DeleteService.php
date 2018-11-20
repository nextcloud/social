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
use OCA\Social\Db\NotesRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ICoreService;
use OCA\Social\Service\MiscService;


class DeleteService implements ICoreService {


	/** @var NotesRequest */
	private $notesRequest;

	/** @var ActivityService */
	private $activityService;

	/** @var NoteService */
	private $noteService;

	/** @var MiscService */
	private $miscService;


	/**
	 * UndoService constructor.
	 *
	 * @param NotesRequest $notesRequest
	 * @param ActivityService $activityService
	 * @param NoteService $noteService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NotesRequest $notesRequest, ActivityService $activityService, NoteService $noteService,
		MiscService $miscService
	) {
		$this->notesRequest = $notesRequest;
		$this->activityService = $activityService;
		$this->noteService = $noteService;
		$this->miscService = $miscService;
	}


	/**
	 * @param ACore $delete
	 *
	 * @throws UnknownItemException
	 */
	public function parse(ACore $delete) {

		if ($delete->gotObject()) {
			$id = $delete->getObject()
						 ->getId();
		} else {
			$id = $delete->getObjectId();
		}

		/** @var Delete $delete */
		try {
			$item = $this->activityService->getItem($id);

			switch ($item->getType()) {

				case 'Note':
					$service = $this->noteService;
					break;

				default:
					throw new UnknownItemException();
			}

			try {
				$service->delete($item);
			} catch (Exception $e) {
				$this->miscService->log(
					2, 'Cannot delete ' . $delete->getType() . ': ' . $e->getMessage()
				);
			}
		} catch (InvalidResourceException $e) {
		}
	}


	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
	}

}

