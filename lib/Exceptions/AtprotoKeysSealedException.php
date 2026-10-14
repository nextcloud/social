<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

/**
 * A Bluesky key this server holds sealed does not open with the instance
 * secret in use: the secret changed and the keys were not re-sealed, so
 * nothing can be signed with it until an administrator does. The message
 * is the one the person is shown; ERROR_CODE is what an answer or a
 * failed move carries beside it, and $which names the key for the log.
 */
class AtprotoKeysSealedException extends AtprotoException {
	public const ERROR_CODE = 'keys-sealed';
	public const MESSAGE = 'This server\'s keys need to be re-sealed by an administrator (occ social:atproto:keys:reseal)';

	public function __construct(
		public readonly string $which = '',
	) {
		parent::__construct(self::MESSAGE);
	}
}
