<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/** With its code (CodedRefusal), so the setup page can say what to pick instead. */
class InvalidHandleException extends Exception {
	use CodedRefusal;
}
