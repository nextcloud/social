<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\StatusApiController;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\PostService;
use OCP\Server;

/**
 * A reply is only accepted to a post its author may read, as Mastodon does:
 * a parent the author may not read is answered exactly like a parent that
 * does not exist, a 404 from the client API and "not found" from the post
 * service the web app writes through, and nothing is posted.
 */
class ReplyParentVisibilityTest extends ApiTestCase {
	private function reply(string $as, string $inReplyToId): ApiResponse {
		return $this->call(
			StatusApiController::class, 'statusNew', [],
			['status' => 'a reply', 'in_reply_to_id' => $inReplyToId, 'visibility' => 'public'], $as, 'POST'
		);
	}

	/** How many statuses the account's own profile lists to itself. */
	private function statusesCount(string $name): int {
		$response = $this->call(
			AccountApiController::class, 'accountStatuses', ['account' => (string)$this->account($name)->getNid()], [], $name
		);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		return count($response->body);
	}

	public function testAReplyToAPostTheAuthorMayNotReadIsANotFoundLikeAMissingOne(): void {
		$hidden = $this->publish('alice', ['status' => 'for my followers', 'visibility' => 'private']);
		$this->account('bob');
		$before = $this->statusesCount('bob');

		$toHidden = $this->reply('bob', $hidden->id);
		$toMissing = $this->reply('bob', '999999999999999999');

		$this->assertSame(404, $toHidden->status, json_encode($toHidden->body) ?: '');
		$this->assertSame(404, $toMissing->status, json_encode($toMissing->body) ?: '');
		$this->assertEquals($toMissing->body, $toHidden->body, 'the two answers do not tell a hidden post from a missing one');
		$this->assertSame($before, $this->statusesCount('bob'), 'nothing was posted');
	}

	public function testAFollowerMayReplyToAFollowersOnlyPost(): void {
		$this->follow('bob', 'alice');
		$parent = $this->publish('alice', ['status' => 'for my followers', 'visibility' => 'private']);

		$reply = $this->reply('bob', $parent->id);

		$this->assertSame(200, $reply->status, json_encode($reply->body) ?: '');
		$this->assertSame($parent->id, $reply->body->in_reply_to_id);
	}

	/**
	 * The post service takes the parent by its address, which is how the web
	 * app and the other writers name it; the same rule holds there.
	 */
	public function testThePostServiceRefusesAParentTheAuthorMayNotReadLikeAMissingOne(): void {
		$hidden = $this->publish('alice', ['status' => 'for my followers', 'visibility' => 'private']);
		$this->account('bob');
		$bob = Server::get(AccountService::class)->getActorFromUserId(self::PREFIX . 'bob');
		$before = $this->statusesCount('bob');

		$messages = [];
		foreach ([$hidden->uri, $hidden->uri . '-missing'] as $parent) {
			$post = new Post($bob);
			$post->setContent('a reply');
			$post->setType('public');
			$post->setReplyTo($parent);
			try {
				Server::get(PostService::class)->createPost($post);
				$this->fail('a reply to ' . $parent . ' was posted');
			} catch (StreamNotFoundException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame($messages[1], $messages[0], 'the same refusal for a hidden parent and a missing one');
		$this->assertSame($before, $this->statusesCount('bob'), 'nothing was posted');
	}
}
