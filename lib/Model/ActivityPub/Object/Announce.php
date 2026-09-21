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

	public function __construct(?ACore $parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}

	/**
	 * @param array $data
	 *
	 * @throws Exception
	 */
	#[\Override]
	public function import(array $data) {
		parent::import($data);

		// Might be better to create 'actor_id' field in the 'server_streams' table.
		//		$this->setAttributedTo($this->getActorId());
	}

	/**
	 * The boost, as a client reads one: a status whose `reblog` is the status
	 * that was boosted.
	 *
	 * Only a Stream can stand in `reblog`, because only a Stream exports the
	 * shape of a status. An `Announce` naming anything else — an actor, a
	 * collection, a type this app has no model for — is a boost of something a
	 * timeline cannot show, and handing a client the wrong shape under a key it
	 * decodes as a status is worse than handing it none.
	 *
	 * @return array
	 */
	#[\Override]
	public function exportAsLocal(): array {
		$result = parent::exportAsLocal();

		$object = $this->getObject();
		if ($object instanceof Stream) {
			$result['reblog'] = $object->exportAsLocal();
		}

		return $result;
	}
}
