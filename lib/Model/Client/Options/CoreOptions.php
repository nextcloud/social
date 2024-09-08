<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client\Options;

use OCA\Social\Model\ActivityPub\ACore;

/**
 * Class TimelineOptions
 *
 * @package OCA\Social\Model\Client\Options
 */
class CoreOptions {
	private int $format = ACore::FORMAT_ACTIVITYPUB;


	/**
	 * @return int
	 */
	public function getFormat(): int {
		return $this->format;
	}

	/**
	 * @param int $format
	 *
	 * @return CoreOptions
	 */
	public function setFormat(int $format): self {
		$this->format = $format;

		return $this;
	}
}
