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


namespace OCA\Social\Model;

use DateTime;
use Exception;
use JsonSerializable;
use OCA\Social\Tools\Traits\TArrayTools;

/**
 * Class StreamQueue
 *
 * @package OCA\Social\Model
 */
class StreamQueue implements JsonSerializable {
	use TArrayTools;

	public const TYPE_CACHE = 'Cache';
	public const TYPE_VERIFY = 'Signature';

	public const STATUS_STANDBY = 0;
	public const STATUS_RUNNING = 1;
	public const STATUS_SUCCESS = 9;

	private int $id = 0;
	private string $token = '';
	private string $streamId = '';
	private string $type = '';
	private int $status = 0;
	private int $tries = 0;
	private int $last = 0;

	public function __construct(string $token = '', string $type = '', string $streamId = '') {
		$this->token = $token;
		$this->type = $type;
		$this->streamId = $streamId;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): StreamQueue {
		$this->id = $id;

		return $this;
	}

	public function getToken(): string {
		return $this->token;
	}

	/**
	 * @param string $token
	 *
	 * @return StreamQueue
	 */
	public function setToken(string $token): StreamQueue {
		$this->token = $token;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getStreamId(): string {
		return $this->streamId;
	}

	/**
	 * @param string $streamId
	 *
	 * @return StreamQueue
	 */
	public function setStreamId(string $streamId): StreamQueue {
		$this->streamId = $streamId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @param string $type
	 *
	 * @return StreamQueue
	 */
	public function setType(string $type): StreamQueue {
		$this->type = $type;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getStatus(): int {
		return $this->status;
	}

	/**
	 * @param int $status
	 *
	 * @return StreamQueue
	 */
	public function setStatus(int $status): StreamQueue {
		$this->status = $status;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getTries(): int {
		return $this->tries;
	}

	/**
	 * @param int $tries
	 *
	 * @return StreamQueue
	 */
	public function setTries(int $tries): StreamQueue {
		$this->tries = $tries;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getLast(): int {
		return $this->last;
	}

	/**
	 * @param int $last
	 *
	 * @return StreamQueue
	 */
	public function setLast(int $last): StreamQueue {
		$this->last = $last;

		return $this;
	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		$this->setId($this->getInt('id', $data, 0));
		$this->setToken($this->get('token', $data, ''));
		$this->setStreamId($this->get('stream_id', $data, ''));
		$this->setType($this->get('type', $data, ''));
		$this->setStatus($this->getInt('status', $data, 0));
		$this->setTries($this->getInt('tries', $data, 0));

		$last = $this->get('last', $data, '');
		if ($last === '') {
			$this->setLast(0);
		} else {
			try {
				$dTime = new DateTime($last);
				$this->setLast($dTime->getTimestamp());
			} catch (Exception $e) {
			}
		}
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'token' => $this->getToken(),
			'streamId' => $this->getStreamId(),
			'type' => $this->getType(),
			'status' => $this->getStatus(),
			'tries' => $this->getTries(),
			'last' => $this->getLast()
		];
	}
}
