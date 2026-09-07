<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Integration bootstrap: unlike tests/bootstrap.php (which stubs OCP so the unit
 * suite runs with no server), this boots the real Nextcloud so the tests exercise
 * the actual database, migrations and dependency container. It expects the app to
 * live inside a server checkout (apps/social) on an installed instance — which is
 * exactly what the phpunit-* workflows set up before invoking
 * `composer run test:integration`.
 *
 * The app's own composer autoloader (already loaded by vendor/bin/phpunit) is safe
 * next to the server: it is classmap-authoritative and maps no OCP\ classes, so
 * the server's autoloader serves everything the stubs would otherwise shadow.
 */

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

// In CI the app sits at <server>/apps/social, so the server root is four levels
// up. On a dev instance the app directory is often a symlink from elsewhere —
// then __DIR__ resolves outside the server tree and NEXTCLOUD_ROOT says where
// the server actually is.
$serverRoot = getenv('NEXTCLOUD_ROOT') ?: __DIR__ . '/../../../..';
if (!is_file($serverRoot . '/lib/base.php')) {
	fwrite(STDERR, "Integration tests need a Nextcloud server: '$serverRoot/lib/base.php' not found."
		. " Set NEXTCLOUD_ROOT to the server directory.\n");
	exit(1);
}

require_once $serverRoot . '/lib/base.php';

\OC_App::loadApp('social');
\OC_Hook::clear();

// A freshly installed CI instance has never run the app's setup, so the cloud
// and social URLs are unset — and everything from actor creation to timeline
// row parsing throws (or silently skips rows) on SocialAppConfigException.
// Configure them the way Config#setCloudAddress would.
$configService = \OCP\Server::get(\OCA\Social\Service\ConfigService::class);
try {
	$configService->getCloudUrl();
} catch (\OCA\Social\Exceptions\SocialAppConfigException $e) {
	$configService->setCloudUrl('http://localhost:8080');
}
try {
	$configService->getSocialUrl();
} catch (\OCA\Social\Exceptions\SocialAppConfigException $e) {
	$configService->setSocialUrl('http://localhost:8080/apps/social/');
}
