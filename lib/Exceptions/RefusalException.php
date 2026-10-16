<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

/** Something the person asked for and can do differently, with its code (CodedRefusal). */
class RefusalException extends InvalidResourceException {
	use CodedRefusal;
}
