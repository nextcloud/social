<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\TimelineApiController;

/**
 * The status inside a boost, and the status a notification is about, are
 * read through a second join onto the posts and their authors. That join
 * selected every column of the author but the numeric id, so the author of
 * every boosted post on a timeline, and of the post in every favourite,
 * reblog and mention notification, reached a client as `"id": "0"`: tapping
 * the name opened account 0.
 */
class BoostedAuthorTest extends ApiTestCase {
	public function testTheAuthorOfABoostedPostHasTheirId(): void {
		$alice = $this->account('alice');
		$post = $this->publish('alice', ['status' => 'worth a boost']);
		$this->follow('carol', 'bob');
		$this->act('bob', $post->id, 'reblog');

		$home = $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'home'], [], 'carol');

		$boosts = array_values(array_filter($home->body, static fn (\stdClass $status): bool => $status->reblog !== null));
		$this->assertCount(1, $boosts);
		$this->assertSame((string)$this->account('bob')->getNid(), $boosts[0]->account->id, 'the booster');
		$this->assertSame((string)$alice->getNid(), $boosts[0]->reblog->account->id, 'the author of what was boosted');
	}

	public function testTheAuthorOfThePostANotificationIsAboutHasTheirId(): void {
		$alice = $this->account('alice');
		$this->acceptEveryNotification('alice');
		$post = $this->publish('alice', ['status' => 'worth a star']);
		$this->act('bob', $post->id, 'favourite');

		$notifications = $this->call(TimelineApiController::class, 'notifications', [], ['types' => ['favourite']], 'alice');

		$this->assertCount(1, $notifications->body);
		$this->assertSame((string)$this->account('bob')->getNid(), $notifications->body[0]->account->id);
		$this->assertSame((string)$alice->getNid(), $notifications->body[0]->status->account->id);
	}
}
