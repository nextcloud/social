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

namespace OCA\Social\Model\ActivityPub\Object;


use DateTime;
use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;


class Note extends ACore implements JsonSerializable {


	const TYPE = 'Note';

	const TYPE_PUBLIC = 'public';
	const TYPE_UNLISTED = 'unlisted';
	const TYPE_FOLLOWERS = 'followers';
	const TYPE_DIRECT = 'direct';


	/** @var string */
	private $content = '';

	/** @var array */
	private $hashtags = [];

	/** @var string */
	private $attributedTo = '';

	/** @var string */
	private $inReplyTo = '';

	/** @var bool */
	private $sensitive = false;

	/** @var string */
	private $conversation = '';

	/** @var int */
	private $publishedTime = 0;


	/**
	 * Note constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
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
	 * @return array
	 */
	public function getHashtags(): array {
		return $this->hashtags;
	}

	/**
	 * @param array $hashtags
	 *
	 * @return Note
	 */
	public function setHashtags(array $hashtags): Note {
		$this->hashtags = $hashtags;

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
		$this->setPublishedTime($dTime->getTimestamp());
	}


	/**
	 *
	 */
	public function fillHashtags() {
		$tags = $this->getTags('Hashtag');
		$hashtags = [];
		foreach ($tags as $tag) {
			$hashtags[] = $tag['name'];
		}

		$this->setHashtags($hashtags);
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		parent::import($data);

		$this->setInReplyTo($this->validate(ACore::AS_ID, 'inReplyTo', $data, ''));
		$this->setAttributedTo($this->validate(ACore::AS_ID, 'attributedTo', $data, ''));
		$this->setSensitive($this->getBool('sensitive', $data, false));
		$this->setConversation($this->validate(ACore::AS_ID, 'conversation', $data, ''));
		$this->setContent($this->get('content', $data, ''));
		$this->convertPublished();

		$this->fillHashtags();
	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		$dTime = new DateTime($this->get('published_time', $data, 'yesterday'));

		$this->setContent($this->validate(self::AS_STRING, 'content', $data, ''));;

		$this->setPublishedTime($dTime->getTimestamp());
		$this->setAttributedTo($this->validate(self::AS_ID, 'attributed_to', $data, ''));
		$this->setInReplyTo($this->validate(self::AS_ID, 'in_reply_to', $data));
		$this->setHashtags($this->getArray('hashtags', $data, []));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$this->addEntryInt('publishedTime', $this->getPublishedTime());

		$result = array_merge(
			parent::jsonSerialize(),
			[
				'content'      => $this->getContent(),
				'attributedTo' => $this->getUrlSocial() . $this->getAttributedTo(),
				'inReplyTo'    => $this->getInReplyTo(),
				'sensitive'    => $this->isSensitive(),
				'conversation' => $this->getConversation()
			]
		);

		if ($this->isCompleteDetails()) {
			$result['hashtags'] = $this->getHashtags();
		}

		return $result;
	}

}

