<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * A router for PHP's built-in server that gives the browser tests the install
 * shapes a production web server gives Nextcloud (.github/workflows/e2e.yml):
 *
 *     E2E_WEBROOT=/nextcloud E2E_PRETTY_URLS=1 \
 *         php -S localhost:8080 -t <docroot> <server>/apps/social/tests/e2e/server/router.php
 *
 * - E2E_WEBROOT: the path Nextcloud lives under, empty for the host's root.
 *   <docroot><webroot> is the server directory; a symlink to it will do.
 * - E2E_PRETTY_URLS=1: what Nextcloud's .htaccess does with mod_rewrite. A
 *   path that is not a file is handed to index.php, which is told so
 *   (`front_controller_active`) and then writes its links without index.php.
 *
 * In every shape /.well-known/ at the host's root reaches Nextcloud: through
 * index.php at the root, and under a sub-path as the redirect Nextcloud's
 * documentation tells an administrator to add.
 *
 * Files, and scripts with a path after them (index.php/apps/social,
 * remote.php/dav), are left to the built-in server.
 */

// false: the built-in server serves it; true: answered here; a string: the
// index.php to run. A closure, so nothing here is left in the global scope
// Nextcloud runs in.
$e2eRoute = (static function (): bool|string {
	$webroot = rtrim((string)getenv('E2E_WEBROOT'), '/');
	$pretty = getenv('E2E_PRETTY_URLS') === '1';
	$docroot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
	$uri = (string)$_SERVER['REQUEST_URI'];
	$path = rawurldecode((string)parse_url($uri, PHP_URL_PATH));
	$wellKnown = str_starts_with($path, '/.well-known/');

	if ($webroot !== '' && $wellKnown) {
		header('Location: ' . $webroot . ($pretty ? '' : '/index.php') . $uri, true, 301);
		return true;
	}
	if ($webroot !== '' && $path !== $webroot && !str_starts_with($path, $webroot . '/')) {
		http_response_code(404);
		return true;
	}

	// for every script, as Apache's SetEnv and nginx's fastcgi_param do
	if ($pretty) {
		putenv('front_controller_active=true');
	}

	$file = $docroot . $path;
	$isScript = preg_match('#\.php(/|$)#', $path) === 1;
	$isFile = is_file($file) || (is_dir($file) && is_file($file . '/index.php'));
	if ($isScript || $isFile || (!$pretty && !$wellKnown)) {
		return false;
	}

	$_SERVER['SCRIPT_NAME'] = $webroot . '/index.php';
	$_SERVER['SCRIPT_FILENAME'] = $docroot . $webroot . '/index.php';
	$_SERVER['PATH_INFO'] = substr($path, strlen($webroot));
	$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] . $_SERVER['PATH_INFO'];
	chdir($docroot . $webroot);

	return $docroot . $webroot . '/index.php';
})();
if (is_bool($e2eRoute)) {
	return $e2eRoute;
}

require $e2eRoute;
