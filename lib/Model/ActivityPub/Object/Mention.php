<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;

class Mention extends Stream implements JsonSerializable {
	public const TYPE = 'Mention';

	public function __construct(?ACore $parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	public function import(array $data): void {
		parent::import($data);
	}

	public function importFromDatabase(array $data): void {
		parent::importFromDatabase($data);
	}

	public function jsonSerialize(): array {
		$result = parent::jsonSerialize();

		return $result;
	}
}
