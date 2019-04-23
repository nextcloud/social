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


use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;


class RequestQueueService {


	use TArrayTools;


	/** @var RequestQueueRequest */
	private $requestQueueRequest;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * RequestQueueService constructor.
	 *
	 * @param RequestQueueRequest $requestQueueRequest
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		RequestQueueRequest $requestQueueRequest, ConfigService $configService,
		MiscService $miscService
	) {
		$this->requestQueueRequest = $requestQueueRequest;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param array $instancePaths
	 * @param ACore $item
	 * @param string $author
	 *
	 * @return string
	 */
	public function generateRequestQueue(array $instancePaths, ACore $item, string $author
	): string {
		$activity = json_encode($item, JSON_UNESCAPED_SLASHES);

		$token = '';
		$requests = [];
		foreach ($instancePaths as $instancePath) {
			$request = new RequestQueue($activity, $instancePath, $author);
			if ($token === '') {
				$token = $request->getToken();
			} else {
				$request->setToken($token);
			}

			$requests[] = $request;
		}

		$this->requestQueueRequest->multiple($requests);

		return $token;
	}


	/**
	 * @param string $token
	 *
	 * @return RequestQueue
	 * @throws EmptyQueueException
	 * @throws NoHighPriorityRequestException
	 */
	public function getPriorityRequest(string $token): RequestQueue {
		$requests = $this->requestQueueRequest->getFromToken($token);

		if (sizeof($requests) === 0) {
			throw new EmptyQueueException();
		}

		$request = $requests[0];
		switch ($request->getPriority()) {

			case InstancePath::PRIORITY_TOP:
				return $request;

			case InstancePath::PRIORITY_HIGH:
				if (sizeof($requests) === 1) {
					return $request;
				}

				$next = $requests[1];
				if ($next->getStatus() < InstancePath::PRIORITY_HIGH) {
					return $request;
				}
				break;

			case InstancePath::PRIORITY_MEDIUM:
				if (sizeof($requests) === 1) {
					return $request;
				}
				break;
		}

		throw new NoHighPriorityRequestException();
	}


	/**
	 * @param int $total
	 *
	 * @return RequestQueue[]
	 */
	public function getRequestStandby(int &$total = 0): array {
		$requests = $this->requestQueueRequest->getStandby();
		$total = sizeof($requests);

		$result = [];
		foreach ($requests as $request) {
			$delay = floor(pow($request->getTries(), 4) / 3);
			if ($request->getLast() < (time() - $delay)) {
				$result[] = $request;
			}
		}

		return $result;
	}


	/**
	 * @param string $token
	 * @param int $status
	 *
	 * @return RequestQueue[]
	 */
	public function getRequestFromToken(string $token, int $status = -1): array {
		if ($token === '') {
			return [];
		}

		return $this->requestQueueRequest->getFromToken($token, $status);
	}


	/**
	 * @param RequestQueue $queue
	 *
	 * @throws QueueStatusException
	 */
	public function initRequest(RequestQueue $queue) {
		$this->requestQueueRequest->setAsRunning($queue);
	}


	/**
	 * @param RequestQueue $queue
	 * @param bool $success
	 */
	public function endRequest(RequestQueue $queue, bool $success) {
		try {
			if ($success === true) {
				$this->requestQueueRequest->setAsSuccess($queue);
			} else {
				$this->requestQueueRequest->setAsFailure($queue);
			}
		} catch (QueueStatusException $e) {
		}
	}


	/**
	 * @param RequestQueue $queue
	 */
	public function deleteRequest(RequestQueue $queue) {
		$this->requestQueueRequest->delete($queue);
	}

}

