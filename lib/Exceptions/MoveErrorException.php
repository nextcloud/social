<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

/**
 * A move refused or stopped, an import or export of the Migration page
 * refused, or a change to the account's own Bluesky account refused (its
 * handle, an app password), with a stable code beside the English sentence:
 * the page explains the code in the person's language, the sentence is for
 * logs and older clients. The codes are listed in Atproto-Compatibility
 * §13.5 and, for the Migration routes, in API.md (Migration error codes).
 */
class MoveErrorException extends RefusalException {
}
