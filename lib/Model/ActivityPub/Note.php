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

namespace OCA\Social\Model\ActivityPub;


use JsonSerializable;

class Note extends Core implements JsonSerializable {


	/** @var string */
	private $content;

	/** @var string */
	private $summary = '';

	/** @var string */
	private $published;

	/** @var string */
	private $attributedTo;

	/** @var string */
	private $inReplyTo = '';


	/**
	 * Note constructor.
	 *
	 * @param bool $isTopLevel
	 */
	public function __construct(bool $isTopLevel = false) {
		parent::__construct($isTopLevel);

		$this->setType('Note');
	}


	/**
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}

	/**
	 * @param string $content
	 *
	 * @return Note
	 */
	public function setContent(string $content): Note {
		$this->content = $content;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getSummary(): string {
		return $this->summary;
	}

	/**
	 * @param string $summary
	 *
	 * @return Note
	 */
	public function setSummary(string $summary): Note {
		$this->summary = $summary;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getPublished(): string {
		return $this->published;
	}

	/**
	 * @param string $published
	 *
	 * @return Note
	 */
	public function setPublished(string $published): Note {
		$this->published = $published;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAttributedTo(): string {
		return $this->attributedTo;
	}

	/**
	 * @param string $attributedTo
	 *
	 * @return Note
	 */
	public function setAttributedTo(string $attributedTo): Note {
		$this->attributedTo = $attributedTo;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getInReplyTo(): string {
		return $this->inReplyTo;
	}

	/**
	 * @param string $inReplyTo
	 *
	 * @return Note
	 */
	public function setInReplyTo(string $inReplyTo): Note {
		$this->inReplyTo = $inReplyTo;

		return $this;
	}











//	public function
//"published": "2018-06-23T17:17:11Z",
//"attributedTo": "https://my-example.com/actor",
//"inReplyTo": "https://mastodon.social/@Gargron/100254678717223630",


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'content'      => $this->getContent(),
				'published'    => $this->getPublished(),
				'attributedTo' => $this->getRoot() . $this->getAttributedTo(),
				'inReplyTo'    => $this->getInReplyTo()
			]
		);
	}

}

