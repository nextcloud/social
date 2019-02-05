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
use OCA\Social\Model\ActivityPub\Actor\Person;


/**
 * Class Post
 *
 * @package OCA\Social\Model
 */
class Post implements JsonSerializable {


	use TArrayTools;


	/** @var Person */
	private $actor;

	/** @var array */
	private $to = [];

	/** @var string */
	private $replyTo = '';

	/** @var string */
	private $content = '';

	/** @var string */
	private $type = '';

	/** @var array */
	private $hashtags = [];


	/**
	 * Post constructor.
	 *
	 * @param Person $actor
	 */
	public function __construct(Person $actor) {
		$this->actor = $actor;
	}

	/**
	 * @return Person
	 */
	public function getActor(): Person {
		return $this->actor;
	}


	/**
	 * @param string $to
	 *
	 * @return Post
	 */
	public function addTo(string $to): Post {
		if ($to !== '') {
			$this->to[] = $to;
		}

		return $this;
	}

	/**
	 * @return array
	 */
	public function getTo(): array {
		return $this->to;
	}

	/**
	 * @param array $to
	 *
	 * @return Post
	 */
	public function setTo(array $to): Post {
		$this->to = $to;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getReplyTo(): string {
		return $this->replyTo;
	}

	/**
	 * @param string $replyTo
	 *
	 * @return Post
	 */
	public function setReplyTo(string $replyTo): Post {
		$this->replyTo = $replyTo;

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
	 * @return Post
	 */
	public function setType(string $type): Post {
		$this->type = $type;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getHashtags(): array {
		return $this->hashtags;
	}

	/**
	 * @param array $hashtags
	 *
	 * @return Post
	 */
	public function setHashtags(array $hashtags): Post {
		$this->hashtags = $hashtags;

		return $this;
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
			'actor'   => $this->getActor(),
			'to'      => $this->getTo(),
			'replyTo' => $this->getReplyTo(),
			'content' => $this->getContent(),
			'type'    => $this->getType()
		];
	}


}

