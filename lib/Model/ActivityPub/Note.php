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


use DateTime;
use JsonSerializable;
use OCA\Social\Service\ActivityService;

class Note extends ACore implements JsonSerializable {


	/** @var string */
	private $content;

	/** @var string */
	private $attributedTo;

	/** @var string */
	private $inReplyTo = '';

	/** @var bool */
	private $sensitive = false;

	/** @var string */
	private $conversation = '';

	/** @var int */
	private $publishedTime;


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


	/**
	 * @return bool
	 */
	public function isSensitive(): bool {
		return $this->sensitive;
	}

	/**
	 * @param bool $sensitive
	 *
	 * @return Note
	 */
	public function setSensitive(bool $sensitive): Note {
		$this->sensitive = $sensitive;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getConversation(): string {
		return $this->conversation;
	}

	/**
	 * @param string $conversation
	 *
	 * @return Note
	 */
	public function setConversation(string $conversation): Note {
		$this->conversation = $conversation;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getPublishedTime(): int {
		return $this->publishedTime;
	}

	/**
	 * @param int $time
	 *
	 * @return Note
	 */
	public function setPublishedTime(int $time): Note {
		$this->publishedTime = $time;

		return $this;
	}

	/**
	 *
	 */
	public function convertPublished() {
		$dTime = new DateTime($this->getPublished());
		$dTime->format(ActivityService::DATE_FORMAT);
		$this->publishedTime = $dTime->getTimestamp();
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		parent::import($data);

		$this->setSummary($this->get('summary', $data, ''));
		$this->setInReplyTo($this->get('inReplyTo', $data, ''));
		$this->setAttributedTo($this->get('attributedTo', $data, ''));
		$this->setSensitive($this->getBool('sensitive', $data, false));
		$this->setConversation($this->get('conversation', $data, ''));
		$this->setContent($this->get('content', $data, ''));
		$this->convertPublished();
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return array_merge(
			parent::jsonSerialize(),
			[
				'content'       => $this->getContent(),
				'publishedTime' => $this->getPublishedTime(),
				'attributedTo'  => $this->getRoot() . $this->getAttributedTo(),
				'inReplyTo'     => $this->getInReplyTo(),
				'sensitive'     => $this->isSensitive(),
				'conversation'  => $this->getConversation()
			]
		);
	}

}

