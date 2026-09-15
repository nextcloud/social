<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * No translation provider answered, so nothing was translated.
 *
 * Mastodon's own answer to this is a 503, and a client reads it as "ask again
 * later" rather than "this post cannot be translated" — which is what the
 * server means: install or enable a translation provider and the same request
 * works.
 */
class TranslationUnavailableException extends Exception {
}
