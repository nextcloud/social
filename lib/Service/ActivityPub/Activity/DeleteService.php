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


namespace OCA\Social\Service\ActivityPub\Activity;


use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Service\ActivityPub\ICoreService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\MiscService;


class DeleteService implements ICoreService {


	/** @var ActivityService */
	private $activityService;


	/** @var MiscService */
	private $miscService;


	/**
	 * UndoService constructor.
	 *
	 * @param ActivityService $activityService
	 * @param MiscService $miscService
	 */
	public function __construct(ActivityService $activityService, MiscService $miscService) {
		$this->activityService = $activityService;
		$this->miscService = $miscService;
	}


	/**
	 * @param ACore $delete
	 * @param ImportService $importService
	 *
	 * @throws InvalidOriginException
	 */
	public function processIncomingRequest(ACore $delete, ImportService $importService) {

		if ($delete->gotObject()) {
			$id = $delete->getObject()
						 ->getId();
		} else {
			$id = $delete->getObjectId();
		}

		$delete->checkOrigin($id);

		/** @var Delete $delete */
		try {
			$item = $this->activityService->getItem($id);
			$service = $importService->getServiceForItem($item);

			// we could use ->activity($delete, $item) but the delete() is important enough to
			// be here, and to use it.
			$service->delete($item);
		} catch (UnknownItemException $e) {
		} catch (InvalidResourceException $e) {
		}
	}



	/**
	 * @param ACore $item
	 * @param ImportService $importService
	 */
	public function processResult(ACore $item, ImportService $importService) {
	}



	/**
	 * @param ACore $activity
	 * @param ACore $item
	 */
	public function activity(Acore $activity, ACore $item) {
	}


	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
	}


	/**
	 * @param ACore $item
	 */
	public function save(ACore $item) {
	}


}

