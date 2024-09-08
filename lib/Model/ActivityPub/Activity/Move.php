<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Activity;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;

class Move extends ACore implements JsonSerializable {
	public const TYPE = 'Move';

	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		parent::import($data);
		$this->setActorId($this->validate(ACore::AS_ID, 'actor', $data, ''));
		$this->setObjectId($this->validate(ACore::AS_ID, 'object', $data, ''));
		$this->setTarget($this->validate(ACore::AS_ID, 'target', $data, ''));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return parent::jsonSerialize();
	}
}
