<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

/**
 * The part of Symfony's `OutputInterface` this app uses.
 *
 * `symfony/console` is listed under `replace` in composer.json — the server
 * brings its own copy at runtime — so the class is absent from the standalone
 * test suite, and `IMigrator::export()`/`import()` take one as their last
 * argument. tests/bootstrap.php aliases this interface onto that name, the way
 * it already does for the server-private exceptions, so a migrator can be
 * driven without a server.
 */
interface ConsoleOutputInterface {
	/**
	 * @param string|iterable $messages
	 */
	public function writeln($messages, int $options = 0);
}
