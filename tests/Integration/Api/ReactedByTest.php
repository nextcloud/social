<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\StatusApiController;

/**
 * `GET /api/v1/statuses/{id}/favourited_by` and `/reblogged_by`: the accounts
 * are read through a join onto the cached actors that selected every column
 * but the numeric id, so each of them came back as `"id": "0"`. A client
 * opens a profile by that id, and every one of them opened account 0.
 */
class ReactedByTest extends ApiTestCase {
	/** @return array<string, array{string, string}> */
	public static function routes(): array {
		return [
			'favourited_by' => ['statusFavouritedBy', 'favourite'],
			'reblogged_by' => ['statusRebloggedBy', 'reblog'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('routes')]
	public function testTheAccountsCarryTheirOwnIds(string $method, string $act): void {
		$status = $this->publish('alice', ['status' => 'a post to react to']);
		$this->act('bob', $status->id, $act);
		$this->act('carol', $status->id, $act);

		$response = $this->call(StatusApiController::class, $method, ['nid' => $status->id], [], 'alice');

		$this->assertSame(200, $response->status);
		$this->assertEntities('Account', $response->body);
		$ids = array_map(static fn (\stdClass $account): string => $account->id, $response->body);
		sort($ids);
		$expected = [(string)$this->account('bob')->getNid(), (string)$this->account('carol')->getNid()];
		sort($expected);
		$this->assertSame($expected, $ids);

		// and the id is one a client can open the account by
		$opened = $this->call(AccountApiController::class, 'accountGet', ['id' => $ids[0]], [], 'alice');
		$this->assertSame(200, $opened->status);
		$this->assertSame($ids[0], $opened->body->id);
	}
}
