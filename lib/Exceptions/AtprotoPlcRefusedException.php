<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Exceptions;

/**
 * The PLC directory looked at an operation and refused it: a verdict, not
 * a failure to reach it. Sending the same bytes again cannot change it.
 */
class AtprotoPlcRefusedException extends AtprotoException {
}
