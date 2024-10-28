<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
	private StreamActionsRequest $streamActionsRequest;

	private MiscService $miscService;


	/**
	 * StreamActionService constructor.
	 *
	 * @param StreamActionsRequest $streamActionsRequest
	 * @param MiscService $miscService
	 */
	public function __construct(StreamActionsRequest $streamActionsRequest, MiscService $miscService,
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
	public function setActionBool(string $actorId, string $streamId, string $key, bool $value): void {
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
