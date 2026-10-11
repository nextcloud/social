<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Service\MigrationService;
use OCP\Server;

/**
 * Bookmarks and favourites are served for posts the reader may read, and an
 * imported bookmark is only kept for a post the importing account may read.
 *
 * A mark is the reader's own row next to somebody else's post, so whether the
 * post is shown is still the author's audience to decide: a followers-only
 * post is in the reader's bookmarks while they follow its author, and drops
 * out of them when they stop, as it drops out of every other timeline.
 */
class MarkedPostVisibilityTest extends ApiTestCase {
	/** @return string[] the ids of the statuses the reader's bookmarks or favourites list */
	private function marked(string $as, string $list): array {
		$response = $this->call(TimelineApiController::class, $list, [], [], $as);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		return array_map(static fn (\stdClass $status): string => $status->id, $response->body);
	}

	/** @return array{bookmarked: int, skipped: int, failed: array<string, string>} */
	private function importBookmarks(string $as, string $uri): array {
		$this->account($as);

		return Server::get(MigrationService::class)->importBookmarks(self::PREFIX . $as, $uri . "\n");
	}

	public function testAnImportedBookmarkOfAPostTheImporterMayNotReadIsNotKept(): void {
		$post = $this->publish('alice', ['status' => 'for my followers', 'visibility' => 'private']);

		$result = $this->importBookmarks('bob', $post->uri);

		$this->assertSame(0, $result['bookmarked']);
		$this->assertArrayHasKey($post->uri, $result['failed'], 'reported as not found or not readable');
		$this->assertNotContains($post->id, $this->marked('bob', 'bookmarks'));
		$this->assertNotContains($post->id, $this->marked('bob', 'favourites'));
	}

	public function testAnImportedBookmarkOfAPublicPostIsKept(): void {
		$post = $this->publish('alice', ['status' => 'for everybody', 'visibility' => 'public']);

		$result = $this->importBookmarks('bob', $post->uri);

		$this->assertSame(1, $result['bookmarked']);
		$this->assertSame([], $result['failed']);
		$this->assertContains($post->id, $this->marked('bob', 'bookmarks'));
	}

	public function testAnImportedBookmarkOfAFollowersOnlyPostIsKeptForAFollower(): void {
		$this->follow('bob', 'alice');
		$post = $this->publish('alice', ['status' => 'for my followers', 'visibility' => 'private']);

		$result = $this->importBookmarks('bob', $post->uri);

		$this->assertSame(1, $result['bookmarked']);
		$this->assertContains($post->id, $this->marked('bob', 'bookmarks'));
	}

	/**
	 * Marked while the reader followed the author, then the follow ended: the
	 * marks stay where they are, and the post is not served from them.
	 */
	public function testAMarkedPostTheReaderMayNoLongerReadIsNotServed(): void {
		$this->follow('bob', 'alice');
		$post = $this->publish('alice', ['status' => 'for my followers', 'visibility' => 'private']);
		$this->act('bob', $post->id, 'bookmark');
		$this->act('bob', $post->id, 'favourite');
		$this->assertContains($post->id, $this->marked('bob', 'bookmarks'));
		$this->assertContains($post->id, $this->marked('bob', 'favourites'));

		$unfollow = $this->call(
			AccountApiController::class, 'accountUnfollow', ['id' => (string)$this->account('alice')->getNid()], [], 'bob', 'POST'
		);
		$this->assertSame(200, $unfollow->status, json_encode($unfollow->body) ?: '');

		$this->assertNotContains($post->id, $this->marked('bob', 'bookmarks'));
		$this->assertNotContains($post->id, $this->marked('bob', 'favourites'));
	}

	/** The author's own followers-only post stays in the author's own lists. */
	public function testTheAuthorStillSeesTheirOwnMarkedFollowersOnlyPost(): void {
		$post = $this->publish('alice', ['status' => 'for my followers', 'visibility' => 'private']);
		$this->act('alice', $post->id, 'bookmark');
		$this->act('alice', $post->id, 'favourite');

		$this->assertContains($post->id, $this->marked('alice', 'bookmarks'));
		$this->assertContains($post->id, $this->marked('alice', 'favourites'));
	}
}
