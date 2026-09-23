<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use RuntimeException;

/** The local edit was stored, but its ActivityPub Update could not be queued. */
class FederationDeliveryException extends RuntimeException {
}
