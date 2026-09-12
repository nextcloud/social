<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use Exception;

/**
 * Thrown by AccessBlockMiddleware and caught by it: the request came from an
 * address this instance answers nothing from.
 *
 * Its own type rather than a bare Exception so that `afterException()` can
 * tell the refusal it raised from every other failure a controller may throw,
 * and re-throw those untouched.
 */
class AccessBlockedException extends Exception {
}
