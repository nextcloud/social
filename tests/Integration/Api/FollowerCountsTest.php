<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;

/**
 * An account's follower count in the client API is the number of people who
 * follow it — the same number the followers list and Statistics give.
 */
class FollowerCountsTest extends ApiTestCase {
	/**
	 * The counters of an account are kept on its cached row and moved by the
	 * follows as they happen. A new account's row used to start at "never
	 * counted", which no follow moves, so its profile said 0 until the cron's
	 * walk counted it.
	 */
	public function testANewAccountsFollowersAreCountedFromItsFirstFollow(): void {
		$this->follow('bob', 'alice');
		$this->follow('carol', 'alice');

		$me = $this->call(AccountApiController::class, 'verifyCredentials', [], [], 'alice');
		$this->assertSame(200, $me->status);
		$this->assertSame(2, $me->body->followers_count);
		$this->assertSame(0, $me->body->following_count);

		$bobId = (string)$this->account('bob')->getNid();
		$bob = $this->call(AccountApiController::class, 'accountGet', ['id' => $bobId], [], 'alice');
		$this->assertSame(0, $bob->body->followers_count);
		$this->assertSame(1, $bob->body->following_count);
	}
}
