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


use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Exceptions\StreamActionDoesNotExistException;
use OCA\Social\Model\StreamAction;


/**
 * Class StreamActionService
 *
 * @package OCA\Social\Service
 */
class StreamActionService {


	/** @var StreamActionsRequest */
	private $streamActionsRequest;

	/** @var MiscService */
	private $miscService;


	/**
	 * StreamActionService constructor.
	 *
	 * @param StreamActionsRequest $streamActionsRequest
	 * @param MiscService $miscService
	 */
	public function __construct(StreamActionsRequest $streamActionsRequest, MiscService $miscService
	) {
		$this->streamActionsRequest = $streamActionsRequest;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $actorId
	 * @param string $streamId
	 * @param string $key
	 * @param string $value
	 */
	public function setAction(string $actorId, string $streamId, string $key, string $value) {
		$action = $this->loadAction($actorId, $streamId);
		$action->updateValue($key, $value);
		$this->saveAction($action);
	}


	/**
	 * @param string $actorId
	 * @param string $streamId
	 * @param string $key
	 * @param int $value
	 */
	public function setActionInt(string $actorId, string $streamId, string $key, int $value) {
		$action = $this->loadAction($actorId, $streamId);
		$action->updateValueInt($key, $value);
		$this->saveAction($action);
	}


	/**
	 * @param string $actorId
	 * @param string $streamId
	 * @param string $key
	 * @param bool $value
	 */
	public function setActionBool(string $actorId, string $streamId, string $key, bool $value) {
		$action = $this->loadAction($actorId, $streamId);
		$action->updateValueBool($key, $value);
		$this->saveAction($action);
	}


	/**
	 * @param string $actorId
	 * @param string $streamId
	 *
	 * @return StreamAction
	 */
	private function loadAction(string $actorId, string $streamId): StreamAction {
		try {
			$action = $this->streamActionsRequest->getAction($actorId, $streamId);
		} catch (StreamActionDoesNotExistException $e) {
			$action = new StreamAction($actorId, $streamId);
		}

		return $action;
	}


	/**
	 * @param StreamAction $action
	 */
	private function saveAction(StreamAction $action) {
		if ($this->streamActionsRequest->update($action) === 0) {
			$this->streamActionsRequest->create($action);
		}
	}
}

