<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use Exception;
use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * Class Follow
 *
 * @package OCA\Social\Model\ActivityPub\Object
 */
class Announce extends Stream implements JsonSerializable {
	public const TYPE = 'Announce';


	/**
	 * Follow constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct(?ACore $parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}


	/**
	 * @param array $data
	 *
	 * @throws Exception
	 */
	public function import(array $data) {
		parent::import($data);

		// Might be better to create 'actor_id' field in the 'server_streams' table.
		//		$this->setAttributedTo($this->getActorId());
	}

	/**
	 * @return array
	 */
	public function exportAsLocal(): array {
		$result = parent::exportAsLocal();

		if ($this->hasObject()) {
			// TODO: check it is a repost/boost
			$result['reblog'] = $this->getObject()->exportAsLocal();
		}

		return $result;
	}
}
