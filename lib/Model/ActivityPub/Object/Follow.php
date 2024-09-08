<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Tools\IQueryRow;

/**
 * Class Follow
 *
 * @package OCA\Social\Model\ActivityPub\Object
 */
class Follow extends ACore implements JsonSerializable, IQueryRow {
	public const TYPE = 'Follow';

	private string $followId = '';
	private string $followIdPrim = '';
	private bool $accepted = false;

	/**
	 * Follow constructor.
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
	public function getFollowId(): string {
		return $this->followId;
	}

	/**
	 * @param string $followId
	 *
	 * @return Follow
	 */
	public function setFollowId(string $followId): Follow {
		$this->followId = $followId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getFollowIdPrim(): string {
		return $this->followIdPrim;
	}

	/**
	 * @param string $followIdPrim
	 *
	 * @return Follow
	 */
	public function setFollowIdPrim(string $followIdPrim): Follow {
		$this->followIdPrim = $followIdPrim;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isAccepted(): bool {
		return $this->accepted;
	}

	/**
	 * @param bool $accepted
	 *
	 * @return Follow
	 */
	public function setAccepted(bool $accepted): Follow {
		$this->accepted = $accepted;

		return $this;
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		parent::import($data);
	}


	/**
	 * @param array $data
	 */
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);

		$this->setAccepted(($this->getInt('accepted', $data, 0) === 1) ? true : false);
		$this->setFollowId($this->get('follow_id', $data, ''));
		$this->setFollowIdPrim($this->get('follow_id_prim', $data, ''));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		$result = parent::jsonSerialize();

		if ($this->isCompleteDetails()) {
			$result = array_merge(
				$result,
				[
					'follow_id' => $this->getFollowId(),
					'follow_id_prim' => $this->getFollowIdPrim(),
					'accepted' => $this->isAccepted()
				]
			);
		}

		return $result;
	}
}
