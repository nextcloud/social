<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub\Object;

use JsonSerializable;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;

/**
 * Class Image
 *
 * @package OCA\Social\Model\ActivityPub
 */
class Image extends Document implements JsonSerializable {
	public const TYPE = 'Image';


	/**
	 * Image constructor.
	 *
	 * @param ACore $parent
	 */
	public function __construct($parent = null) {
		parent::__construct($parent);

		$this->setType(self::TYPE);
	}


	/**
	 * @param array $data
	 *
	 * @throws UrlCloudException
	 * @throws InvalidOriginException
	 */
	public function import(array $data) {
		parent::import($data);
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return parent::jsonSerialize();
	}
}
