<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Mention;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\NotificationGroupService;
use PHPUnit\Framework\TestCase;

/**
 * Grouping notifications.
 *
 * The thing to get right is what counts as one event. Group too little and a
 * popular post buries everything else; group too much and two people writing
 * to you become one row, which loses one of them.
 */
class NotificationGroupServiceTest extends TestCase {
	private NotificationGroupService $service;

	protected function setUp(): void {
		$this->service = new NotificationGroupService();
	}

	private function post(int $nid): Note {
		$post = new Note();
		$post->setNid($nid);
		$post->setId('https://cloud.example/@alice/' . $nid);

		return $post;
	}

	private function sender(int $nid): Person {
		$person = new Person();
		$person->setNid($nid);
		$person->setId('https://cloud.example/users/' . $nid);

		return $person;
	}

	/**
	 * A notification as the timeline hands one over: a Stream of the
	 * notification sub-type, carrying who did it and what to.
	 */
	private function notification(int $nid, string $subType, int $senderNid, ?Note $about): Stream {
		$notification = new Stream();
		$notification->setNid($nid);
		$notification->setId('https://cloud.example/notifications/' . $nid);
		$notification->setSubType($subType);
		$notification->setPublishedTime(1_700_000_000 + $nid);
		$notification->setActor($this->sender($senderNid));
		if ($about !== null) {
			$notification->setObject($about);
		}

		return $notification;
	}

	public function testFavouritesOfOnePostAreOneGroup(): void {
		$post = $this->post(10);
		$grouped = $this->service->group([
			$this->notification(3, Like::TYPE, 300, $post),
			$this->notification(2, Like::TYPE, 200, $post),
			$this->notification(1, Like::TYPE, 100, $post),
		]);

		$this->assertCount(1, $grouped['notification_groups']);
		$group = $grouped['notification_groups'][0];
		$this->assertSame('favourite-10', $group['group_key']);
		$this->assertSame(3, $group['notifications_count']);
		$this->assertSame('10', $group['status_id']);
		$this->assertSame(['300', '200', '100'], $group['sample_account_ids']);
	}

	/** The post is carried once, not once per notification. */
	public function testTheStatusIsCarriedOnce(): void {
		$post = $this->post(10);
		$grouped = $this->service->group([
			$this->notification(2, Like::TYPE, 200, $post),
			$this->notification(1, Like::TYPE, 100, $post),
		]);

		$this->assertCount(1, $grouped['statuses']);
		$this->assertCount(2, $grouped['accounts']);
	}

	public function testFavouritesOfDifferentPostsAreDifferentGroups(): void {
		$grouped = $this->service->group([
			$this->notification(2, Like::TYPE, 200, $this->post(11)),
			$this->notification(1, Like::TYPE, 100, $this->post(10)),
		]);

		$this->assertCount(2, $grouped['notification_groups']);
	}

	/** Two people writing to you are two things to read. */
	public function testMentionsAreNeverGrouped(): void {
		$post = $this->post(10);
		$grouped = $this->service->group([
			$this->notification(2, Mention::TYPE, 200, $post),
			$this->notification(1, Mention::TYPE, 100, $post),
		]);

		$this->assertCount(2, $grouped['notification_groups']);
		$this->assertSame('ungrouped-2', $grouped['notification_groups'][0]['group_key']);
	}

	/**
	 * The key names what the group is, never the notifications in it: one
	 * built from ids would name a different group as soon as one more
	 * arrived, and a client's dismiss would miss.
	 */
	public function testTheKeyIsStableAsMoreArrive(): void {
		$post = $this->post(10);
		$first = $this->service->group([$this->notification(1, Like::TYPE, 100, $post)]);
		$later = $this->service->group([
			$this->notification(9, Like::TYPE, 900, $post),
			$this->notification(1, Like::TYPE, 100, $post),
		]);

		$this->assertSame(
			$first['notification_groups'][0]['group_key'],
			$later['notification_groups'][0]['group_key']
		);
	}

	public function testThePageBoundsAreTheIdsOnThePage(): void {
		$post = $this->post(10);
		$group = $this->service->group([
			$this->notification(9, Like::TYPE, 900, $post),
			$this->notification(1, Like::TYPE, 100, $post),
		])['notification_groups'][0];

		$this->assertSame('9', $group['most_recent_notification_id']);
		$this->assertSame('1', $group['page_min_id']);
		$this->assertSame('9', $group['page_max_id']);
	}

	/** Ids are snowflakes: '9' is not larger than '10'. */
	public function testTheBoundsAreComparedAsNumbers(): void {
		$post = $this->post(10);
		$group = $this->service->group([
			$this->notification(10, Like::TYPE, 900, $post),
			$this->notification(9, Like::TYPE, 100, $post),
		])['notification_groups'][0];

		$this->assertSame('10', $group['page_max_id']);
		$this->assertSame('9', $group['page_min_id']);
	}

	public function testAtMostEightAccountsAreSampled(): void {
		$post = $this->post(10);
		$notifications = [];
		for ($i = 1; $i <= 20; $i++) {
			$notifications[] = $this->notification($i, Like::TYPE, $i * 10, $post);
		}

		$group = $this->service->group(array_reverse($notifications))['notification_groups'][0];

		$this->assertSame(20, $group['notifications_count']);
		$this->assertCount(NotificationGroupService::SAMPLE_ACCOUNTS, $group['sample_account_ids']);
	}

	/** A client may ask for less grouping than the default. */
	public function testAClientMayAskForFewerGroupedTypes(): void {
		$post = $this->post(10);
		$grouped = $this->service->group(
			[
				$this->notification(2, Like::TYPE, 200, $post),
				$this->notification(1, Like::TYPE, 100, $post),
			],
			['reblog']
		);

		$this->assertCount(2, $grouped['notification_groups']);
	}

	public function testOneGroupCanBeFoundAgainByItsKey(): void {
		$post = $this->post(10);
		$notifications = [
			$this->notification(3, Mention::TYPE, 300, $post),
			$this->notification(2, Like::TYPE, 200, $post),
			$this->notification(1, Like::TYPE, 100, $post),
		];

		$members = $this->service->membersOf($notifications, 'favourite-10');

		$this->assertCount(2, $members);
		$this->assertSame([2, 1], array_map(static fn (Stream $n): int => $n->getNid(), $members));
	}
}
