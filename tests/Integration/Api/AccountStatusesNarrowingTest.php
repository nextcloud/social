<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;

/**
 * `exclude_replies` and `tagged` on `GET /api/v1/accounts/{id}/statuses`, as
 * Mastodon answers them: `exclude_replies` leaves out the account's replies
 * to other accounts and keeps its replies to itself, a thread, and its
 * boosts; `tagged` keeps only the account's posts carrying the hashtag, and
 * no boosts. Both narrow the page query, so a page is full and paging on
 * `max_id` walks the narrowed list.
 */
class AccountStatusesNarrowingTest extends ApiTestCase {
	/**
	 * @param array<string, mixed> $query
	 * @return list<string> the page in its order: a post by its id, a boost
	 *                      as `boost of` the post it repeats
	 */
	private function idsOf(string $account, array $query = []): array {
		$response = $this->call(
			AccountApiController::class, 'accountStatuses', ['account' => (string)$this->account($account)->getNid()], $query, 'alice'
		);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertEntities('Status', $response->body);

		return array_map(
			static fn (\stdClass $status): string => $status->reblog === null ? $status->id : 'boost of ' . $status->reblog->id,
			$response->body
		);
	}

	/**
	 * Every id the list holds, read two at a time on `max_id`.
	 *
	 * @param array<string, mixed> $query
	 * @return list<string>
	 */
	private function walked(string $account, array $query): array {
		$walked = [];
		$maxId = '';
		for ($pages = 0; $pages < 10; $pages++) {
			$page = $this->idsOf($account, $query + ['limit' => 2] + ($maxId === '' ? [] : ['max_id' => $maxId]));
			if ($page === []) {
				break;
			}
			$this->assertLessThanOrEqual(2, count($page));
			array_push($walked, ...$page);
			$maxId = $this->lastIdOf($account, $query + ['limit' => 2] + ($maxId === '' ? [] : ['max_id' => $maxId]));
		}

		return $walked;
	}

	/**
	 * The id of the last status on a page, a boost's own, which is the
	 * cursor of the next.
	 *
	 * @param array<string, mixed> $query
	 */
	private function lastIdOf(string $account, array $query): string {
		$response = $this->call(
			AccountApiController::class, 'accountStatuses', ['account' => (string)$this->account($account)->getNid()], $query, 'alice'
		);
		$this->assertNotSame([], $response->body);

		return $response->body[count($response->body) - 1]->id;
	}

	/** @param list<string> $ids */
	private static function newestFirst(array $ids): array {
		usort($ids, static fn (string $a, string $b): int => \OCA\Social\Tools\Nid::compare($b, $a));

		return $ids;
	}

	public function testExcludeRepliesKeepsTheThreadsAndTheBoostsAndLeavesOutRepliesToOthers(): void {
		$carols = $this->publish('carol', ['status' => 'carol asks something', 'visibility' => 'public']);
		$opening = $this->publish('bob', ['status' => 'bob starts a thread', 'visibility' => 'public']);
		$thread = $this->publish('bob', ['status' => 'and goes on with it', 'visibility' => 'public', 'in_reply_to_id' => $opening->id]);
		$answer = $this->publish('bob', ['status' => '@' . self::PREFIX . 'carol bob answers carol', 'visibility' => 'public', 'in_reply_to_id' => $carols->id]);
		$further = $this->publish('bob', ['status' => 'a third in the thread', 'visibility' => 'public', 'in_reply_to_id' => $thread->id]);
		$this->act('bob', $carols->id, 'reblog');
		$alone = $this->publish('bob', ['status' => 'and something on its own', 'visibility' => 'public']);

		$everything = $this->idsOf('bob');
		$this->assertContains($answer->id, $everything, 'without the parameter the reply to carol is there');
		$this->assertEqualsCanonicalizing(
			[$opening->id, $thread->id, $answer->id, $further->id, 'boost of ' . $carols->id, $alone->id], $everything
		);

		// the same list in the same order, less the reply to carol
		$expected = array_values(array_diff($everything, [$answer->id]));
		$this->assertSame($expected, $this->idsOf('bob', ['exclude_replies' => 'true']), 'a page of twenty');
		$this->assertSame($expected, $this->walked('bob', ['exclude_replies' => 'true']), 'paged two at a time');
	}

	public function testTaggedKeepsOnlyTheAccountsPostsWithTheHashtag(): void {
		$tag = 'apictrtag' . bin2hex(random_bytes(3));
		$carols = $this->publish('carol', ['status' => 'carol writes #' . $tag, 'visibility' => 'public']);
		$tagged = [];
		for ($i = 0; $i < 3; $i++) {
			$tagged[] = $this->publish('bob', ['status' => 'bob, number ' . $i . ', #' . $tag, 'visibility' => 'public'])->id;
			$this->publish('bob', ['status' => 'bob without it, number ' . $i, 'visibility' => 'public']);
		}
		$this->publish('bob', ['status' => 'bob with another #' . $tag . 'other', 'visibility' => 'public']);
		$this->act('bob', $carols->id, 'reblog');

		$expected = self::newestFirst($tagged);
		$this->assertSame($expected, $this->idsOf('bob', ['tagged' => $tag]), 'only the tagged posts, and no boost of one');
		$this->assertSame($expected, $this->idsOf('bob', ['tagged' => '#' . strtoupper($tag)]), 'with its # and in capitals');
		$this->assertSame($expected, $this->walked('bob', ['tagged' => $tag]), 'paged two at a time');
		$this->assertSame([], $this->idsOf('bob', ['tagged' => $tag . 'none']), 'a hashtag the account never used');
	}
}
