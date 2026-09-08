<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Standalone unit-test bootstrap.
 *
 * The suite runs without a Nextcloud server: OCP interfaces come from the
 * `nextcloud/ocp` dev dependency, the app's own classes from the composer
 * autoloader. Anything that would need a booted server (database, config,
 * the `OC` container) is mocked in the tests themselves.
 */

// friendica/json-ld trips a compile-time deprecation on PHP 8.5; it is a
// dependency, not our code, so keep it out of the test output.
$previous = error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../vendor/autoload.php';
error_reporting($previous);

/**
 * OCP interfaces, from the `nextcloud/ocp` dev dependency.
 *
 * Registered here rather than through composer's `autoload-dev`, deliberately:
 * the app's autoloader is loaded by the server at runtime, so a psr-4 entry for
 * `OCP\` there would shadow the server's real interfaces with these stubs on
 * any installation that has dev dependencies present, and a server class
 * carrying `#[\Override]` against a method the stub lacks then fails to load.
 */
spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCP\\')) {
		return;
	}

	$file = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

// `\OC` is the server's static root and is not part of the OCP stubs. A few code
// paths reach it for the container, so provide one that hands out registered test
// doubles (see TestContainer).
if (!class_exists('OC', false)) {
	class OC {
		public static ?\OCA\Social\Tests\Helper\TestContainer $server = null;
	}
}
OC::$server = new \OCA\Social\Tests\Helper\TestContainer();

// Exceptions the app throws that live in the server's private namespace and are
// therefore absent from the OCP stubs. Declared so the paths that raise them
// stay reachable in tests.
if (!class_exists('OC\\User\\NoUserException', false)) {
	class_alias(\OCA\Social\Tests\Helper\NoUserException::class, 'OC\\User\\NoUserException');
}
