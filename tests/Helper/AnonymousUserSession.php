<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use LogicException;
use OCP\IUser;
use OCP\IUserSession;

/**
 * A session with nobody in it.
 *
 * Nextcloud 35 made `Response::getHeaders()` resolve `IUserSession` through
 * the container so it can add an `X-User-Id` header, which means every test
 * that renders any response now needs one. Anonymous is the right default:
 * it adds no header, so it changes nothing a test already asserts. A test
 * that needs a signed-in user registers its own double instead.
 */
class AnonymousUserSession implements IUserSession {
	public function login($uid, $password) {
		throw new LogicException('login() is not part of this double');
	}

	public function logout() {
		throw new LogicException('logout() is not part of this double');
	}

	public function setUser($user) {
		throw new LogicException('setUser() is not part of this double');
	}

	public function setVolatileActiveUser(?IUser $user): void {
		throw new LogicException('setVolatileActiveUser() is not part of this double');
	}

	public function getUser() {
		return null;
	}

	public function isLoggedIn() {
		return false;
	}

	public function getImpersonatingUserID(): ?string {
		return null;
	}

	public function setImpersonatingUserID(bool $useCurrentUser = true): void {
		throw new LogicException('setImpersonatingUserID() is not part of this double');
	}
}
