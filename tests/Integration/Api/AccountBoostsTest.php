<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Integration\Helper\RowSeeder;

/**
 * `GET /api/v1/accounts/{id}/statuses` lists the account's boosts beside its
 * posts, as Mastodon does: each a status by the booster whose `reblog` is
 * the boosted post, in the order the account did them. `exclude_reblogs`
 * leaves them out, as does a page narrowed to media, which a boost carries
 * none of. A boost of a post the reader may not see is left out rather than
 * served without what it repeats.
 */
class AccountBoostsTest extends ApiTestCase {
	/** @param array<string, mixed> $query */
	private function statusesOf(string $account, ?string $reader, array $query = []): ApiResponse {
		$response = $this->call(
			AccountApiController::class, 'accountStatuses', ['account' => (string)$this->account($account)->getNid()], $query, $reader
		);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		return $response;
	}

	/**
	 * The ids a page boosts, by the status they repeat.
	 *
	 * @return list<string>
	 */
	private static function boosted(array $page): array {
		return array_values(array_map(
			static fn (\stdClass $status): string => $status->reblog->id,
			array_filter($page, static fn (\stdClass $status): bool => $status->reblog !== null)
		));
	}

	public function testABoostIsOnTheBoostersStatusesWithThePostItRepeats(): void {
		$this->follow('alice', 'bob');
		$post = $this->publish('carol', ['status' => 'carol says something worth passing on', 'visibility' => 'public']);
		$own = $this->publish('bob', ['status' => 'and bob says something himself', 'visibility' => 'public']);
		$this->act('bob', $post->id, 'reblog');

		foreach (['alice' => 'a follower', 'carol' => 'the author of the boosted post', 'bob' => 'the booster'] as $reader => $who) {
			$page = $this->statusesOf('bob', $reader, ['limit' => 10])->body;
			$this->assertEntities('Status', $page, $who);
			$this->assertSame([$post->id], self::boosted($page), $who . ': the boost, of carol\'s post');
			$boost = array_values(array_filter($page, static fn (\stdClass $status): bool => $status->reblog !== null))[0];
			$this->assertSame((string)$this->account('bob')->getNid(), $boost->account->id, $who . ': by bob');
			$this->assertSame((string)$this->account('carol')->getNid(), $boost->reblog->account->id, $who . ': of carol\'s');
			$this->assertContains($own->id, array_map(static fn (\stdClass $status): string => $status->id, $page), $who . ': beside his own post');
		}

		$anonymous = $this->statusesOf('bob', null, ['limit' => 10])->body;
		$this->assertSame([$post->id], self::boosted($anonymous), 'a public boost of a public post, read by nobody');
	}

	/** Reported as an empty list: the account had made nothing but a boost. */
	public function testAnAccountWhoseOnlyStatusIsABoostIsNotEmpty(): void {
		$post = $this->publish('carol', ['status' => 'the only thing bob ever passed on', 'visibility' => 'public']);
		$this->act('bob', $post->id, 'reblog');

		$page = $this->statusesOf('bob', 'alice')->body;

		$this->assertCount(1, $page);
		$this->assertSame([$post->id], self::boosted($page));
	}

	public function testExcludeReblogsAndAMediaPageLeaveTheBoostsOut(): void {
		$post = $this->publish('carol', ['status' => 'boosted, and not asked for', 'visibility' => 'public']);
		$own = $this->publish('bob', ['status' => 'bob\'s own words', 'visibility' => 'public']);
		$this->act('bob', $post->id, 'reblog');

		$excluded = $this->statusesOf('bob', 'alice', ['exclude_reblogs' => 'true'])->body;
		$this->assertSame([], self::boosted($excluded), 'exclude_reblogs');
		$this->assertSame([$own->id], array_map(static fn (\stdClass $status): string => $status->id, $excluded), 'and the posts stay');

		foreach ([['only_media' => 'true'], ['media_type' => 'image'], ['only_short' => 'true'], ['tagged' => 'apictr']] as $narrowing) {
			$this->assertSame([], self::boosted($this->statusesOf('bob', 'alice', $narrowing)->body), json_encode($narrowing) ?: '');
		}
	}

	/**
	 * The boosted post no longer addressed to the public: only its author,
	 * and whoever it is addressed to, may read it, and the boost of it is
	 * nobody else's to see on the booster's profile.
	 */
	public function testABoostOfAPostTheReaderMayNotSeeIsLeftOut(): void {
		$post = $this->publish('carol', ['status' => 'boosted, then made private', 'visibility' => 'public']);
		$own = $this->publish('bob', ['status' => 'still here', 'visibility' => 'public']);
		$this->act('bob', $post->id, 'reblog');
		RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM_DEST, [
			'stream_id' => md5($post->uri), 'actor_id' => md5(Stream::CONTEXT_PUBLIC),
		]);

		foreach (['alice' => 'a reader who may not see it', 'bob' => 'the booster, who may no longer see it either'] as $reader => $who) {
			$page = $this->statusesOf('bob', $reader)->body;
			$this->assertSame([], self::boosted($page), $who);
			$this->assertSame([$own->id], array_map(static fn (\stdClass $status): string => $status->id, $page), $who . ': the post stays');
			$this->assertStringNotContainsString('made private', json_encode($page) ?: '', $who);
		}
		$this->assertStringNotContainsString('made private', json_encode($this->statusesOf('bob', null)->body) ?: '', 'nobody');

		$this->assertSame([$post->id], self::boosted($this->statusesOf('bob', 'carol')->body), 'its author still may');
	}

	/**
	 * Paged two at a time on `max_id`, boosts and posts together: every one
	 * once, newest first, and the boosts' place in the order is when they
	 * were made.
	 */
	public function testPagingOnMaxIdWalksBoostsAndPostsTogether(): void {
		$expected = [];
		for ($i = 0; $i < 3; $i++) {
			$post = $this->publish('carol', ['status' => 'carol ' . $i, 'visibility' => 'public']);
			$expected[] = 'post ' . $this->publish('bob', ['status' => 'bob ' . $i, 'visibility' => 'public'])->id;
			$this->act('bob', $post->id, 'reblog');
			$expected[] = 'boost of ' . $post->id;
		}

		$whole = $this->statusesOf('bob', 'alice', ['limit' => 20])->body;
		$this->assertCount(6, $whole);
		$ids = array_map(static fn (\stdClass $status): string => $status->id, $whole);
		$sorted = $ids;
		usort($sorted, static fn (string $a, string $b): int => \OCA\Social\Tools\Nid::compare($b, $a));
		$this->assertSame($sorted, $ids, 'newest first, boosts and posts in one order');

		$walked = [];
		$maxId = 0;
		for ($pages = 0; $pages < 5; $pages++) {
			$page = $this->statusesOf('bob', 'alice', ['limit' => 2] + ($maxId === 0 ? [] : ['max_id' => $maxId]))->body;
			if ($page === []) {
				break;
			}
			$this->assertLessThanOrEqual(2, count($page));
			foreach ($page as $status) {
				$walked[] = $status->reblog === null ? 'post ' . $status->id : 'boost of ' . $status->reblog->id;
			}
			$maxId = $page[count($page) - 1]->id;
		}

		$this->assertSame(
			array_map(static fn (\stdClass $status): string => $status->reblog === null ? 'post ' . $status->id : 'boost of ' . $status->reblog->id, $whole),
			$walked,
			'the pages are the whole list, in its order'
		);
		sort($expected);
		sort($walked);
		$this->assertSame($expected, $walked, 'every post and every boost, once');
	}
}
