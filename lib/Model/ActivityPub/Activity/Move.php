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
	#[\Override]
	public function import(array $data) {
		parent::import($data);
		$this->setActorId($this->validate(ACore::AS_ID, 'actor', $data, ''));
		$this->setObjectId($this->validate(ACore::AS_ID, 'object', $data, ''));
		$this->setTarget($this->validate(ACore::AS_ID, 'target', $data, ''));
	}

	/**
	 * `target` is where the account went, and it is the only reason the
	 * activity is worth sending: the generic export knows nothing of it, so a
	 * Move without this override says an account moved and not where to.
	 */
	#[\Override]
	public function exportAsActivityPub(): array {
		if ($this->getTarget() !== '') {
			$this->addEntry('target', $this->getTarget());
		}

		return parent::exportAsActivityPub();
	}

	/**
	 * @return array
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return parent::jsonSerialize();
	}
}
