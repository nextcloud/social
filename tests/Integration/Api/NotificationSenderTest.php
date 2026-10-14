<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\NotificationController;
use OCA\Social\Controller\TimelineApiController;

/**
 * `account_id` on `/api/v1/notifications` and `/api/v2/notifications`: the
 * notifications one account caused, named by the numeric id a client holds.
 */
class NotificationSenderTest extends ApiTestCase {
	#[\Override]
	protected function accountNames(): array {
		return ['alice', 'bob', 'carol'];
	}

	/** bob and carol each mention alice; the sender of each of alice's notifications, by id */
	private function mentioned(): void {
		$this->acceptEveryNotification('alice');
		$this->publish('bob', ['status' => '@' . self::PREFIX . 'alice from bob']);
		$this->publish('carol', ['status' => '@' . self::PREFIX . 'alice from carol']);
	}

	public function testTheListIsNarrowedToOneSender(): void {
		$this->mentioned();
		$bob = (string)$this->account('bob')->getNid();

		$all = $this->call(TimelineApiController::class, 'notifications', [], [], 'alice');
		$this->assertCount(2, $all->body, 'both mentions without a sender named');

		$fromBob = $this->call(TimelineApiController::class, 'notifications', [], ['account_id' => $bob], 'alice');
		$this->assertSame(200, $fromBob->status);
		$this->assertSame([$bob], array_map(static fn (\stdClass $n): string => $n->account->id, $fromBob->body));
	}

	public function testTheGroupedListIsNarrowedToOneSender(): void {
		$this->mentioned();
		$carol = (string)$this->account('carol')->getNid();

		$response = $this->call(NotificationController::class, 'indexV2', [], ['account_id' => $carol], 'alice');
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertCount(1, $response->body->notification_groups);
		$this->assertSame([$carol], $response->body->notification_groups[0]->sample_account_ids);
	}

	public function testAnAccountNobodyHoldsHasCausedNone(): void {
		$this->mentioned();

		$response = $this->call(TimelineApiController::class, 'notifications', [], ['account_id' => '1'], 'alice');
		$this->assertSame(200, $response->status);
		$this->assertSame([], $response->body);
	}
}
