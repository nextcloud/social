<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Internal;

use Exception;
use JsonSerializable;
use OCA\Social\Model\ActivityPub\Stream;

class SocialAppNotification extends Stream implements JsonSerializable {
	public const TYPE = 'SocialAppNotification';


	/**
	 * Notification constructor.
	 *
	 * @param null $parent
	 */
	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}


	/**
	 * @param array $data
	 *
	 * @throws Exception
	 */
	public function import(array $data) {
		//parent::import($data);
	}


	/**
	 * @param array $data
	 *
	 * @throws Exception
	 */
	public function importFromDatabase(array $data) {
		parent::importFromDatabase($data);
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		//		$this->addEntryInt('publishedTime', $this->getPublishedTime());

		return array_merge(
			parent::jsonSerialize(),
			[
			]
		);
	}
}
