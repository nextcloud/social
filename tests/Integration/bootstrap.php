<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Integration bootstrap: unlike tests/bootstrap.php (which stubs OCP so the unit
 * suite runs with no server), this boots the real Nextcloud so the tests exercise
 * the actual database, migrations and dependency container. It only runs in CI,
 * where `occ maintenance:install` + `app:enable social` have set the app up; the
 * `composer test:integration` script is what the phpunit workflow invokes.
 */

require_once __DIR__ . '/../../../../lib/base.php';

\OC_App::loadApp('social');

if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
	require_once __DIR__ . '/../../vendor/autoload.php';
}
