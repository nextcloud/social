<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;

/**
 * `GET /api/v1/accounts/familiar_followers`: who of the people I follow also
 * follows this account — the "followed by X and Y" line under a profile.
 *
 * Every local account follows itself: the loopback follow is how its own
 * posts reach its own home timeline. The query matched that row like any
 * other, so the reader was always listed among the people they follow, and
 * a client read "followed by you" under every account the reader followed.
 */
class FamiliarFollowersTest extends ApiTestCase {
	/** @return string[] the usernames bob is shown as alice's familiar followers */
	private function familiarOfAliceForBob(): array {
		$response = $this->call(AccountApiController::class, 'familiarFollowers', [], [
			'id' => [(string)$this->account('alice')->getNid()],
		], 'bob');
		$this->assertSame(200, $response->status);
		$this->assertCount(1, $response->body);

		return array_map(static fn (\stdClass $account): string => $account->username, $response->body[0]->accounts);
	}

	public function testTheReaderIsNotOneOfTheirOwnFamiliarFollowers(): void {
		$this->follow('bob', 'alice');

		$this->assertSame([], $this->familiarOfAliceForBob());
	}

	public function testSomebodyTheReaderFollowsWhoFollowsTheAccountIsListed(): void {
		$this->follow('bob', 'alice');
		$this->follow('bob', 'carol');
		$this->follow('carol', 'alice');

		$this->assertSame([self::PREFIX . 'carol'], $this->familiarOfAliceForBob());
	}
}
