<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Tools\Nid;

/**
 * Favourites and bookmarks are listed in the order they were made, as
 * Mastodon lists them, and paged on the mark rather than on the post.
 *
 * A post written a while ago and marked today is the newest mark, so it is
 * first — ordered by the post's id it was at the bottom, below marks made
 * weeks ago, and beyond the first page's `max_id` it was never reached.
 */
class MarkOrderTest extends ApiTestCase {
	/** One page of the reader's list. */
	private function page(string $list, array $query = []): ApiResponse {
		$response = $this->call(TimelineApiController::class, $list, [], $query, 'bob');
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		return $response;
	}

	/** @return string[] */
	private function ids(ApiResponse $response): array {
		return array_map(static fn (\stdClass $status): string => $status->id, $response->body);
	}

	/**
	 * @return array{old: string, newer: string, newest: string} three of
	 *                                                           alice's posts, the first of them older
	 *                                                           than the other two by a second
	 */
	private function posts(): array {
		$old = $this->publish('alice', ['status' => 'written a while ago', 'visibility' => 'public'])->id;
		// a status id is a snowflake with a resolution of a second
		sleep(1);
		$newer = $this->publish('alice', ['status' => 'written later', 'visibility' => 'public'])->id;
		$newest = $this->publish('alice', ['status' => 'written last', 'visibility' => 'public'])->id;
		if (Nid::compare($newer, $newest) > 0) {
			[$newer, $newest] = [$newest, $newer];
		}

		return ['old' => $old, 'newer' => $newer, 'newest' => $newest];
	}

	/** one mark after the other, with room between them for the millisecond clock to move */
	private function mark(string $act, string ...$ids): void {
		foreach ($ids as $id) {
			$this->act('bob', $id, $act);
			usleep(2000);
		}
	}

	public function testAnOldPostFavouritedTodayIsFirst(): void {
		$posts = $this->posts();
		$this->mark('favourite', $posts['newer'], $posts['newest'], $posts['old']);

		$this->assertSame(
			[$posts['old'], $posts['newest'], $posts['newer']],
			$this->ids($this->page('favourites')),
			'newest favourite first, whatever the age of the post'
		);
	}

	public function testAnOldPostBookmarkedTodayIsFirst(): void {
		$posts = $this->posts();
		$this->mark('bookmark', $posts['newer'], $posts['newest'], $posts['old']);

		$this->assertSame([$posts['old'], $posts['newest'], $posts['newer']], $this->ids($this->page('bookmarks')));
	}

	/**
	 * The cursors in `Link` name the mark, which is what the list is cut on:
	 * following `next` from a page reaches the marks made before the oldest
	 * one on it, however old or new the posts are.
	 */
	public function testThePagesFollowTheOrderOfTheMarks(): void {
		$posts = $this->posts();
		$this->mark('favourite', $posts['newer'], $posts['newest'], $posts['old']);

		$head = $this->page('favourites', ['limit' => 2]);
		$this->assertSame([$posts['old'], $posts['newest']], $this->ids($head));
		$links = $head->links();
		$this->assertArrayHasKey('next', $links);
		$this->assertNotContains($links['next']['query']['max_id'] ?? '', [$posts['old'], $posts['newest']], 'the cursor is the mark, not the post');

		$second = $this->page('favourites', $links['next']['query']);
		$this->assertSame([$posts['newer']], $this->ids($second), 'next is the marks made earlier');
		$this->assertArrayNotHasKey('next', $second->links(), 'a short page is the last one');

		$back = $this->page('favourites', $second->links()['prev']['query'] ?? []);
		$this->assertSame([$posts['old'], $posts['newest']], $this->ids($back), 'prev from there is the head again');

		// `since_id` is the newest page above the cursor, as everywhere
		$this->assertSame(
			[$posts['old'], $posts['newest']],
			$this->ids($this->page('favourites', ['limit' => 2, 'since_id' => $second->links()['prev']['query']['min_id']]))
		);
	}

	/** Unfavouriting and favouriting again is a new mark, so the post moves to the top. */
	public function testFavouritingAgainMovesThePostToTheTop(): void {
		$posts = $this->posts();
		$this->mark('favourite', $posts['old'], $posts['newest']);
		$this->assertSame([$posts['newest'], $posts['old']], $this->ids($this->page('favourites')));

		$this->mark('unfavourite', $posts['old']);
		$this->mark('favourite', $posts['old']);

		$this->assertSame([$posts['old'], $posts['newest']], $this->ids($this->page('favourites')));
	}
}
