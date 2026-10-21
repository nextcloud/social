<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Repository;

use OCA\Social\Exceptions\AtprotoException;

/** The repository head changed since a caller's conditional write began. */
final class InvalidSwapException extends AtprotoException {
}
