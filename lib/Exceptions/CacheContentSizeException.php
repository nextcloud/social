<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

use Exception;

/**
 * The content is larger than what its actual type is allowed to be.
 *
 * Raised after the type has been sniffed rather than before, because the
 * ceiling depends on it: a video is copied to storage a chunk at a time and may
 * be large, while an image is read whole into memory to be stripped and
 * resized and may not. A file that claimed to be a video to get past the
 * request-time check is caught here, where what it really is, is known.
 */
class CacheContentSizeException extends Exception {
}
