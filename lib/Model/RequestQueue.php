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
use daita\MySmallPhpTools\Traits\TStringTools;
use DateTime;
use Exception;
use JsonSerializable;


/**
 * Class RequestQueue
 *
 * @package OCA\Social\Model
 */
class RequestQueue implements JsonSerializable {


	use TArrayTools;
	use TStringTools;


	const STATUS_STANDBY = 0;
	const STATUS_RUNNING = 1;
	const STATUS_SUCCESS = 9;


	/** @var integer */
	private $id = 0;

	/** @var string */
	private $token = '';

	/** @var string */
	private $author = '';

	/** @var string */
	private $activity = '';

	/** @var InstancePath */
	private $instance;

	/** @var int */
	private $priority = 0;

	/** @var int */
	private $status = 0;

	/** @var int */
	private $tries = 0;

	/** @var int */
	private $last = 0;

	/** @var int */
	private $timeout = 5;


	/**
	 * RequestQueue constructor.
	 *
	 * @param string $activity
	 * @param InstancePath $instance
	 * @param string $author
	 */
	public function __construct(string $activity = '', $instance = null, string $author = '') {
		$this->setActivity($activity);
		if ($instance instanceof InstancePath) {
			$this->setInstance($instance);
		}

		$this->setAuthor($author);
		$this->resetToken();
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
	 * @return RequestQueue
	 */
	public function setId(int $id): RequestQueue {
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
	 * @return RequestQueue
	 */
	public function setToken(string $token): RequestQueue {
		$this->token = $token;

		return $this;
	}

	/**
	 * @return RequestQueue
	 */
	public function resetToken(): RequestQueue {
		$uuid = $this->uuid();
		$this->setToken($uuid);

		return $this;
	}


	/**
	 * @return string
	 */
	public function getAuthor(): string {
		return $this->author;
	}

	/**
	 * @param string $author
	 *
	 * @return RequestQueue
	 */
	public function setAuthor(string $author): RequestQueue {
		$this->author = $author;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getActivity(): string {
		return $this->activity;
	}

	/**
	 * @param string $activity
	 *
	 * @return RequestQueue
	 */
	public function setActivity(string $activity): RequestQueue {
		$this->activity = $activity;

		return $this;
	}


	/**
	 * @return InstancePath
	 */
	public function getInstance(): InstancePath {
		return $this->instance;
	}

	/**
	 * @param InstancePath $instance
	 *
	 * @return RequestQueue
	 */
	public function setInstance(InstancePath $instance): RequestQueue {
		$this->setPriority($instance->getPriority());
		$this->instance = $instance;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getPriority(): int {
		return $this->priority;
	}

	/**
	 * @param int $priority
	 *
	 * @return RequestQueue
	 */
	public function setPriority(int $priority): RequestQueue {
		$this->priority = $priority;

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
	 * @return RequestQueue
	 */
	public function setStatus(int $status): RequestQueue {
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
	 * @return RequestQueue
	 */
	public function setTries(int $tries): RequestQueue {
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
	 * @return RequestQueue
	 */
	public function setLast(int $last): RequestQueue {
		$this->last = $last;

		return $this;
	}


	/**
	 * @param int $timeout
	 *
	 * @return RequestQueue
	 */
	public function setTimeout(int $timeout): RequestQueue {
		$this->timeout = $timeout;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getTimeout(): int {
		return $this->timeout;
	}

	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		$instance = new InstancePath();
		$instance->import(json_decode($this->get('instance', $data, '{}'), true));

		$this->setId($this->getInt('id', $data, 0));
		$this->setToken($this->get('token', $data, ''));
		$this->setAuthor($this->get('author', $data, ''));
		$this->setInstance($instance);
		$this->setPriority($this->getInt('priority', $data, 0));
		$this->setActivity($this->get('activity', $data, ''));
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
			'author'   => $this->getAuthor(),
			'instance' => $this->getInstance(),
			'priority' => $this->getPriority(),
			'status'   => $this->getStatus(),
			'tries'    => $this->getTries(),
			'last'     => $this->getLast()
		];
	}

}

