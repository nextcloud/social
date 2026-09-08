<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

/**
 * Interface IQueryRow
 *
 * @deprecated
 * @package OCA\Social\Tools
 */
interface IQueryRow {
	/**
	 * import data to feed the model.
	 *
	 * @param array $data
	 */
	public function importFromDatabase(array $data);
}
