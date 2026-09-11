<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\UserMigration;

use OCP\UserMigration\UserMigrationException;

/**
 * What SocialMigrator throws when a part of the export or import that cannot
 * be skipped fails. The framework stops the whole account export or import on
 * it, so it is raised only where carrying on would produce an archive that
 * lies about what is in it.
 */
class SocialMigratorException extends UserMigrationException {
}
