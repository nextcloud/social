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
use JsonSerializable;

class Post implements JsonSerializable {


	use TArrayTools;


	/** @var string */
	private $userId = '';

	/** @var array */
	private $to = [];

	/** @var string */
	private $replyTo = '';

	/** @var string */
	private $content;

	public function __construct($userId = '') {
		$this->userId = $userId;
	}

	/**
	 * @return string
	 */
	public function getUserId(): string {
		return $this->userId;
	}


	/**
	 * @param string $to
	 */
	public function addTo(string $to) {
		if ($to === '') {
			return;
		}

		$this->to[] = $to;
	}

	/**
	 * @return array
	 */
	public function getTo(): array {
		return $this->to;
	}

	/**
	 * @param array $to
	 */
	public function setTo(array $to) {
		$this->to = $to;
	}


	/**
	 * @return string
	 */
	public function getReplyTo(): string {
		return $this->replyTo;
	}

	/**
	 * @param string $replyTo
	 */
	public function setReplyTo(string $replyTo) {
		$this->replyTo = $replyTo;
	}


	/**
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}

	/**
	 * @param string $content
	 */
	public function setContent(string $content) {
		$this->content = $content;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'userId'  => $this->getUserId(),
			'to'      => $this->getTo(),
			'replyTo' => $this->getReplyTo(),
			'content' => $this->getContent()
		];
	}


}

