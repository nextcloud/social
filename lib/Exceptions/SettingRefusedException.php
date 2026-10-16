<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use InvalidArgumentException;

/**
 * A value of an administrator's form refused, with its code and the field it
 * names (CodedRefusal). An InvalidArgumentException, as the settings
 * services threw before there were codes.
 */
class SettingRefusedException extends InvalidArgumentException {
	use CodedRefusal;
}
