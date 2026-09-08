<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

/**
 * A valid bearer token was presented, but its granted scopes do not cover the
 * requested route. Kept apart from ClientNotFoundException so the client gets
 * told the actual problem instead of "token revoked".
 */
class InsufficientScopeException extends ClientException {
}
