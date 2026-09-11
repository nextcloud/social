<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

/**
 * Stands in for `OC\Hooks\Emitter`.
 *
 * `OCP\Files\IRootFolder` extends it, so the interface has to exist before that
 * one can be loaded — and it lives in the server's private namespace, which the
 * `nextcloud/ocp` stubs do not carry. Aliased in `tests/bootstrap.php`, the same
 * way `OC\User\NoUserException` is, and for the same reason.
 *
 * The signatures are the server's; nothing in this app calls them, and a test
 * that mocks `IRootFolder` only needs them to be declared.
 */
interface HooksEmitter {
	public function listen(string $scope, string $method, callable $callback): void;

	public function removeListener(?string $scope = null, ?string $method = null, ?callable $callback = null): void;
}
