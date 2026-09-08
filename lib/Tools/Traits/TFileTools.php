<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Traits;

/**
 * Trait TFileTools
 *
 * @package OCA\Social\Tools\Traits
 */
trait TFileTools {
	/**
	 * @param $stream
	 *
	 * @return string
	 */
	protected function getChecksumFromStream($stream): string {
		$ctx = hash_init('md5');
		hash_update_stream($ctx, $stream);

		return hash_final($ctx);
	}
}
