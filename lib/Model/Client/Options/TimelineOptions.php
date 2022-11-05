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


namespace OCA\Social\Model\Client\Options;

use JsonSerializable;
use OCA\Social\Exceptions\UnknownTimelineException;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IRequest;

/**
 * Class TimelineOptions
 *
 * @package OCA\Social\Model\Client\Options
 */
class TimelineOptions extends CoreOptions implements JsonSerializable {
	use TArrayTools;

	private string $timeline = '';
	private bool $local = false;
	private bool $remote = false;
	private bool $onlyMedia = false;
	private int $minId = 0;
	private int $maxId = 0;
	private int $sinceId = 0;
	private int $limit = 20;
	private bool $inverted = false;

	public static array $availableTimelines = [
		'home',
		'local',
		'public'
	];


	/**
	 * TimelineOptions constructor.
	 *
	 * @param IRequest|null $request
	 */
	public function __construct(IRequest $request = null) {
		if ($request !== null) {
			$this->fromArray($request->getParams());
		}
	}


	/**
	 * @return string
	 */
	public function getTimeline(): string {
		return $this->timeline;
	}

	/**
	 * @param string $timeline
	 *
	 * @return TimelineOptions
	 * @throws UnknownTimelineException
	 */
	public function setTimeline(string $timeline): self {
		$timeline = strtolower($timeline);
		if (!in_array($timeline, self::$availableTimelines)) {
			throw new UnknownTimelineException(
				'unknown timeline: ' . implode(', ', self::$availableTimelines)
			);
		}

		$this->timeline = $timeline;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isLocal(): bool {
		return $this->local;
	}

	/**
	 * @param bool $local
	 *
	 * @return TimelineOptions
	 */
	public function setLocal(bool $local): self {
		$this->local = $local;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isRemote(): bool {
		return $this->remote;
	}

	/**
	 * @param bool $remote
	 *
	 * @return TimelineOptions
	 */
	public function setRemote(bool $remote): self {
		$this->remote = $remote;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isOnlyMedia(): bool {
		return $this->onlyMedia;
	}

	/**
	 * @param bool $onlyMedia
	 *
	 * @return TimelineOptions
	 */
	public function setOnlyMedia(bool $onlyMedia): self {
		$this->onlyMedia = $onlyMedia;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getMinId(): int {
		return $this->minId;
	}

	/**
	 * @param int $minId
	 *
	 * @return TimelineOptions
	 */
	public function setMinId(int $minId): self {
		$this->minId = $minId;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getMaxId(): int {
		return $this->maxId;
	}

	/**
	 * @param int $maxId
	 *
	 * @return TimelineOptions
	 */
	public function setMaxId(int $maxId): self {
		$this->maxId = $maxId;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getSinceId(): int {
		return $this->sinceId;
	}

	/**
	 * @param int $sinceId
	 *
	 * @return TimelineOptions
	 */
	public function setSinceId(int $sinceId): self {
		$this->sinceId = $sinceId;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getLimit(): int {
		return $this->limit;
	}

	/**
	 * @param int $limit
	 *
	 * @return TimelineOptions
	 */
	public function setLimit(int $limit): self {
		$this->limit = $limit;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isInverted(): bool {
		return $this->inverted;
	}

	/**
	 * @param bool $inverted
	 *
	 * @return TimelineOptions
	 */
	public function setInverted(bool $inverted): self {
		$this->inverted = $inverted;

		return $this;
	}


	/**
	 * @param array $arr
	 *
	 * @return TimelineOptions
	 */
	public function fromArray(array $arr): self {
		$this->setLocal($this->getBool('local', $arr, $this->isLocal()));
		$this->setRemote($this->getBool('remote', $arr, $this->isRemote()));
		$this->setRemote($this->getBool('only_media', $arr, $this->isOnlyMedia()));
		$this->setMinId($this->getInt('min_id', $arr, $this->getMinId()));
		$this->setMaxId($this->getInt('max_id', $arr, $this->getMaxId()));
		$this->setSinceId($this->getInt('since_id', $arr, $this->getSinceId()));
		$this->setLimit($this->getInt('limit', $arr, $this->getLimit()));

		return $this;
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return
			[
				'timeline' => $this->getTimeline(),
				'local' => $this->isLocal(),
				'remote' => $this->isRemote(),
				'only_media' => $this->isOnlyMedia(),
				'min_id' => $this->getMinId(),
				'max_id' => $this->getMaxId(),
				'since_id' => $this->getSinceId(),
				'limit' => $this->getLimit()
			];
	}
}
