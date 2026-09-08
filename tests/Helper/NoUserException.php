<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

/**
 * Stand-in for the server's `OC\User\NoUserException`, which is private to the
 * server and so absent from the OCP stubs the suite runs against.
 *
 * A user-defined class rather than an alias of `RuntimeException`, because
 * `class_alias()` refuses an internal class as its first argument.
 */
class NoUserException extends \RuntimeException {
}
