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


use daita\MySmallPhpTools\Model\Cache;
use daita\MySmallPhpTools\Model\CacheItem;
use DateTime;
use Exception;
use JsonSerializable;
use OCA\Social\Model\StreamAction;


class Stream extends ACore implements JsonSerializable {


	const TYPE_PUBLIC = 'public';
	const TYPE_UNLISTED = 'unlisted';
	const TYPE_FOLLOWERS = 'followers';
	const TYPE_DIRECT = 'direct';


	/** @var string */
	private $activityId;

	/** @var string */
	private $content = '';

	/** @var string */
	private $attributedTo = '';

	/** @var string */
	private $inReplyTo = '';

	/** @var bool */
	private $sensitive = false;

	/** @var string */
	private $conversation = '';

	/** @var Cache */
	private $cache = null;

	/** @var int */
	private $publishedTime = 0;

	/** @var StreamAction */
	private $action = null;


	public function __construct($parent = null) {
		parent::__construct($parent);
	}


	/**
	 * @return string
	 */
	public function getActivityId(): string {
		return $this->activityId;
	}

	/**
	 * @param string $activityId
	 *
	 * @return Stream
	 */
	public function setActivityId(string $activityId): Stream {
		$this->activityId = $activityId;

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
	 *
	 * @return Stream
	 */
	public function setContent(string $content): Stream {
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
	 * @return Stream
	 */
	public function setAttributedTo(string $attributedTo): Stream {
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
	 * @return Stream
	 */
	public function setInReplyTo(string $inReplyTo): Stream {
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
	 * @return Stream
	 */
	public function setSensitive(bool $sensitive): Stream {
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
	 * @return Stream
	 */
	public function setConversation(string $conversation): Stream {
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
	 * @return Stream
	 */
	public function setPublishedTime(int $time): Stream {
		$this->publishedTime = $time;

		return $this;
	}

	/**
	 *
	 * @throws Exception
	 */
	public function convertPublished() {
		$dTime = new DateTime($this->getPublished());
		$this->setPublishedTime($dTime->getTimestamp());
	}


	/**
	 * @return bool
	 */
	public function gotCache(): bool {
		return ($this->cache !== null);
	}

	/**
	 * @return Cache
	 */
	public function getCache(): Cache {
		return $this->cache;
	}

	/**
	 * @param Cache $cache
	 *
	 * @return Stream
	 */
	public function setCache(Cache $cache): Stream {
		$this->cache = $cache;

		return $this;
	}


	public function addCacheItem(string $url): Stream {
		$cacheItem = new CacheItem($url);

		if (!$this->gotCache()) {
			$this->setCache(new Cache());
		}

		$this->getCache()
			 ->addItem($cacheItem);

		return $this;
	}


	/**
	 * @return StreamAction
	 */
	public function getAction(): StreamAction {
		return $this->action;
	}

	/**
	 * @param StreamAction $action
	 *
	 * @return Stream
	 */
	public function setAction(StreamAction $action): Stream {
		$this->action = $action;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function hasAction(): bool {
		return ($this->action !== null);
	}


	/**
	 * @param array $data
	 *
	 * @throws Exception
	 */
	public function import(array $data) {
		parent::import($data);

		$this->setInReplyTo($this->validate(self::AS_ID, 'inReplyTo', $data, ''));
		$this->setAttributedTo($this->validate(self::AS_ID, 'attributedTo', $data, ''));
		$this->setSensitive($this->getBool('sensitive', $data, false));
		$this->setObjectId($this->get('object', $data, ''));
		$this->setConversation($this->validate(self::AS_ID, 'conversation', $data, ''));
		$this->setContent($this->get('content', $data, ''));
		$this->convertPublished();
	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		try {
			$dTime = new DateTime($this->get('published_time', $data, 'yesterday'));
			$this->setPublishedTime($dTime->getTimestamp());
		} catch (Exception $e) {
		}

		$this->setActivityId($this->validate(self::AS_ID, 'activity_id', $data, ''));
		$this->setContent($this->validate(self::AS_STRING, 'content', $data, ''));
		$this->setObjectId($this->validate(self::AS_ID, 'object_id', $data, ''));
		$this->setAttributedTo($this->validate(self::AS_ID, 'attributed_to', $data, ''));
		$this->setInReplyTo($this->validate(self::AS_ID, 'in_reply_to', $data));

		$cache = new Cache();
		$cache->import($this->getArray('cache', $data, []));
		$this->setCache($cache);
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$result = array_merge(
			parent::jsonSerialize(),
			[
				'content'      => $this->getContent(),
				'attributedTo' => ($this->getAttributedTo() !== '') ? $this->getUrlSocial()
																	  . $this->getAttributedTo(
					) : '',
				'inReplyTo'    => $this->getInReplyTo(),
				'sensitive'    => $this->isSensitive(),
				'conversation' => $this->getConversation()
			]
		);

		if ($this->isCompleteDetails()) {
			$result = array_merge(
				$result,
				[
					'action'        => ($this->hasAction()) ? $this->getAction() : [],
					'cache'         => ($this->gotCache()) ? $this->getCache() : '',
					'publishedTime' => $this->getPublishedTime()
				]
			);
		}

		$this->cleanArray($result);

		return $result;
	}

}

