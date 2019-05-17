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


use daita\MySmallPhpTools\Traits\TArrayTools;
use DateTime;
use Exception;
use JsonSerializable;


/**
 * Class StreamQueue
 *
 * @package OCA\Social\Model
 */
class StreamQueue implements JsonSerializable {


	use TArrayTools;


	const TYPE_CACHE = 'Cache';
	const TYPE_VERIFY = 'Signature';

	const STATUS_STANDBY = 0;
	const STATUS_RUNNING = 1;
	const STATUS_SUCCESS = 9;


	/** @var integer */
	private $id = 0;

	/** @var string */
	private $token = '';

	/** @var string */
	private $streamId = '';

	/** @var string */
	private $type = '';

	/** @var int */
	private $status = 0;

	/** @var int */
	private $tries = 0;

	/** @var int */
	private $last = 0;


	/**
	 * StreamQueue constructor.
	 *
	 * @param string $token
	 * @param string $type
	 * @param string $streamId
	 */
	public function __construct(string $token = '', string $type = '', string $streamId = '') {
		$this->token = $token;
		$this->type = $type;
		$this->streamId = $streamId;
	}


	/**
	 * @return int
	 */
	public function getId(): int {
		return $this->id;
	}

	/**
	 * @param int $id
	 *
	 * @return StreamQueue
	 */
	public function setId(int $id): StreamQueue {
		$this->id = $id;

		return $this;
	}


	/**
	 * @return string
	 */
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
			'id'       => $this->getId(),
			'token'    => $this->getToken(),
			'streamId' => $this->getStreamId(),
			'type'     => $this->getType(),
			'status'   => $this->getStatus(),
			'tries'    => $this->getTries(),
			'last'     => $this->getLast()
		];
	}

}

