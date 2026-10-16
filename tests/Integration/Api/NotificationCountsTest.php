<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\NotificationController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Service\MarkerService;
use OCP\Server;

/**
 * The two counts a client polls about notifications, against the database:
 * the senders waiting in the requests inbox, which the policy's summary
 * counts without reading the notifications whole, and the unread groups of
 * `/api/v2/notifications/unread_count`, which stop at the marker.
 */
class NotificationCountsTest extends ApiTestCase {
	#[\Override]
	protected function accountNames(): array {
		return ['alice', 'bob', 'carol', 'dave', 'erin'];
	}

	/** @return array{requests: int, notifications: int} what the inbox lists */
	private function inbox(): array {
		$response = $this->call(NotificationController::class, 'requests', [], [], 'alice');
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		return [
			'requests' => count($response->body),
			'notifications' => array_sum(array_map(static fn (\stdClass $row): int => (int)$row->notifications_count, $response->body)),
		];
	}

	/** @return array{requests: int, notifications: int} what the policy's summary says */
	private function summary(): array {
		$response = $this->call(NotificationController::class, 'policy', [], [], 'alice');
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		return [
			'requests' => $response->body->summary->pending_requests_count,
			'notifications' => $response->body->summary->pending_notifications_count,
		];
	}

	/**
	 * alice keeps the policy a new account starts with, which holds back
	 * whoever she does not follow: bob mentions her twice and writes to her
	 * directly, erin favourites her post, dave mentions her and is dismissed,
	 * and carol, whom she follows, is not held at all.
	 */
	public function testTheSummaryCountsWhatTheRequestsInboxLists(): void {
		$this->account('alice');
		$this->follow('alice', 'carol');
		$post = $this->publish('alice', ['status' => 'a post of alice\'s']);

		$this->publish('bob', ['status' => '@' . self::PREFIX . 'alice once']);
		$this->publish('bob', ['status' => '@' . self::PREFIX . 'alice twice']);
		$this->publish('bob', ['status' => '@' . self::PREFIX . 'alice between us', 'visibility' => 'direct']);
		$this->act('erin', $post->id, 'favourite');
		$this->publish('carol', ['status' => '@' . self::PREFIX . 'alice from a friend']);
		$this->publish('dave', ['status' => '@' . self::PREFIX . 'alice from dave']);
		$this->assertSame(['requests' => 3, 'notifications' => 5], $this->inbox(), 'bob, erin and dave wait');
		$this->assertSame($this->inbox(), $this->summary());

		$dismissed = $this->call(
			NotificationController::class, 'requestDismiss', ['id' => (string)$this->account('dave')->getNid()], [], 'alice', 'POST'
		);
		$this->assertSame(200, $dismissed->status);

		$this->assertSame(['requests' => 2, 'notifications' => 4], $this->inbox());
		$this->assertSame($this->inbox(), $this->summary());
	}

	/**
	 * Waits for the next second. A nid orders by its second first and by
	 * chance within one, and the tags are built from the newest.
	 */
	private function nextSecond(): void {
		$second = time();
		while (time() === $second) {
			usleep(20000);
		}
	}

	/** Polled again with the tag it was given, the policy is not counted again until something changed. */
	public function testThePolicyIsRevalidatedUntilANotificationArrives(): void {
		$this->account('alice');
		$this->publish('bob', ['status' => '@' . self::PREFIX . 'alice once']);
		$first = $this->call(NotificationController::class, 'policy', [], [], 'alice');
		$etag = $first->headers['ETag'] ?? '';
		$this->assertNotSame('', $etag);

		$again = $this->call(NotificationController::class, 'policy', [], [], 'alice', headers: ['If-None-Match' => $etag]);
		$this->assertSame(304, $again->status);

		$this->nextSecond();
		$this->publish('erin', ['status' => '@' . self::PREFIX . 'alice too']);
		$changed = $this->call(NotificationController::class, 'policy', [], [], 'alice', headers: ['If-None-Match' => $etag]);
		$this->assertSame(200, $changed->status);
		$this->assertSame(2, $changed->body->summary->pending_requests_count);
	}

	/** What is past the marker is unread, as groups; what the marker has passed is not. */
	public function testTheUnreadGroupsStopAtTheMarker(): void {
		$this->acceptEveryNotification('alice');
		$post = $this->publish('alice', ['status' => 'a post of alice\'s']);
		$this->act('bob', $post->id, 'favourite');
		$this->act('carol', $post->id, 'favourite');
		$this->publish('dave', ['status' => '@' . self::PREFIX . 'alice hello']);

		$count = fn (array $headers = []): ApiResponse => $this->call(
			NotificationController::class, 'unreadCountV2', [], [], 'alice', headers: $headers
		);
		$this->assertSame(2, $count()->body->count, 'two favourites of one post are one group');

		$newest = $this->call(TimelineApiController::class, 'notifications', [], ['limit' => 1], 'alice')->body[0]->id;
		Server::get(MarkerService::class)->set(self::PREFIX . 'alice', 'notifications', (string)$newest);
		$read = $count();
		$this->assertSame(0, $read->body->count, 'read up to the newest');

		$this->assertSame(304, $count(['If-None-Match' => $read->headers['ETag'] ?? ''])->status);

		$this->nextSecond();
		$this->publish('erin', ['status' => '@' . self::PREFIX . 'alice again']);
		$this->assertSame(1, $count(['If-None-Match' => $read->headers['ETag'] ?? ''])->body->count);
	}
}
