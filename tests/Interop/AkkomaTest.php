<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use PHPUnit\Framework\TestCase;

/**
 * This app and a real Akkoma, both ways, in the few things everybody does:
 * follow, post, reply, favourite (and an emoji reaction), boost and delete.
 *
 * Akkoma is Pleroma's fork and speaks Pleroma's ActivityPub: `EmojiReact`
 * for a reaction, its own addressing and its own signer. Both sides are read
 * through their Mastodon-compatible client APIs.
 *
 * Our `admin` and Akkoma's `interop` follow each other before every test.
 */
class AkkomaTest extends TestCase {
	use LocalSide;

	private const USER = 'admin';
	private const REACTION = '🎉';

	private Akkoma $akkoma;
	private Here $here;
	/** our id for Akkoma's `interop` */
	private string $interopHere = '';
	/** Akkoma's id for our `admin` */
	private string $adminThere = '';

	protected function setUp(): void {
		$akkoma = Akkoma::fromEnvironment();
		if ($akkoma === null) {
			$this->markTestSkipped('no Akkoma to talk to: set AKKOMA_BASE_URL and AKKOMA_TOKEN');
		}

		$this->akkoma = $akkoma;
		$this->here = Here::forUser(self::USER);
		$this->interopHere = $this->here->resolveAccount($this->akkoma->handle());
		$this->adminThere = $this->akkoma->resolveAccount($this->localHandle(self::USER));

		$this->follows($this->akkoma, $this->adminThere, 'Akkoma');
		$this->follows($this->here, $this->interopHere, 'this side');
	}

	public function testOurFollowIsAcceptedByAkkoma(): void {
		$this->here->unfollow($this->interopHere);
		$this->drainQueue();
		$this->assertTrue(
			$this->akkoma->awaitRelationship($this->adminThere, 'followed_by', false),
			'Akkoma still counts our account as a follower after the unfollow'
		);

		$this->follows($this->here, $this->interopHere, 'this side');

		$this->assertTrue(
			$this->akkoma->awaitRelationship($this->adminThere, 'followed_by', true),
			'Akkoma does not count our account as a follower'
		);
	}

	public function testAkkomasFollowIsAcceptedHere(): void {
		$this->akkoma->unfollow($this->adminThere);
		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'followed_by', false),
			'the unfollow from Akkoma did not end the follow here'
		);

		$this->follows($this->akkoma, $this->adminThere, 'Akkoma');

		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'followed_by', true),
			'this side does not count Akkoma\'s account as a follower'
		);
	}

	public function testOurPostArrives(): void {
		$words = $this->unique();
		$ours = $this->here->publish($words, ['visibility' => 'public']);
		$this->drainQueue();

		$theirs = $this->akkoma->awaitStatus($this->adminThere, (string)$ours['uri']);

		$this->assertNotNull($theirs, 'the post never reached Akkoma');
		$this->assertStringContainsString($words, (string)$theirs['content']);
		$this->assertSame('public', $theirs['visibility'] ?? '');
	}

	public function testAkkomasPostArrives(): void {
		$words = $this->unique();
		$sent = $this->akkoma->publish($words, ['visibility' => 'public']);

		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);

		$this->assertNotNull($status, 'the post never reached the home timeline of a follower here');
		$this->assertStringContainsString($words, (string)$status['content']);
	}

	public function testOurReplyThreadsUnderTheAkkomaPost(): void {
		$sent = $this->akkoma->publish($this->unique(), ['visibility' => 'public']);
		$ourCopy = $this->here->awaitOnTimeline('home', (string)$sent['uri']);
		$this->assertNotNull($ourCopy, 'the post never reached this side');

		$reply = $this->here->publish($this->unique('reply'), ['visibility' => 'public', 'in_reply_to_id' => $ourCopy['id']]);
		$this->drainQueue();

		$threaded = $this->akkoma->await(function () use ($sent, $reply): ?array {
			$context = $this->akkoma->get('/api/v1/statuses/' . rawurlencode((string)$sent['id']) . '/context');

			return ClientApi::findByUri(is_array($context['descendants'] ?? null) ? $context['descendants'] : [], (string)$reply['uri']);
		});
		$this->assertNotNull($threaded, 'the reply is not in the Akkoma thread it answers');
	}

	public function testAkkomasReplyThreadsUnderOurPost(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->akkoma->awaitStatus($this->adminThere, (string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'the post never reached Akkoma');

		$sent = $this->akkoma->publish($this->unique('reply'), ['visibility' => 'public', 'in_reply_to_id' => $theirCopy['id']]);

		$threaded = $this->here->await(function () use ($ours, $sent): ?array {
			$context = $this->here->get('/api/v1/statuses/' . rawurlencode((string)$ours['id']) . '/context');

			return ClientApi::findByUri(is_array($context['descendants'] ?? null) ? $context['descendants'] : [], (string)$sent['uri']);
		});
		$this->assertNotNull($threaded, 'the reply from Akkoma is not in the thread of the post it answers');
	}

	public function testOurFavouriteAndBoostAreCountedThere(): void {
		$sent = $this->akkoma->publish($this->unique(), ['visibility' => 'public']);
		$ourCopy = $this->here->awaitOnTimeline('home', (string)$sent['uri']);
		$this->assertNotNull($ourCopy, 'the post never reached this side');
		$id = rawurlencode((string)$ourCopy['id']);

		$this->here->post('/api/v1/statuses/' . $id . '/favourite');
		$this->here->post('/api/v1/statuses/' . $id . '/reblog');
		$this->drainQueue();

		$this->assertNotNull(
			$this->akkoma->awaitStatusThat((string)$sent['id'], static fn (array $s): bool => (int)($s['favourites_count'] ?? 0) === 1),
			'the favourite was not counted on Akkoma'
		);
		$this->assertNotNull(
			$this->akkoma->awaitStatusThat((string)$sent['id'], static fn (array $s): bool => (int)($s['reblogs_count'] ?? 0) === 1),
			'the boost was not counted on Akkoma'
		);
	}

	public function testAkkomasFavouriteReactionAndBoostAreCountedHere(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->akkoma->awaitStatus($this->adminThere, (string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'the post never reached Akkoma');
		$id = (string)$theirCopy['id'];

		$this->akkoma->post('/api/v1/statuses/' . rawurlencode($id) . '/favourite');
		$this->akkoma->post('/api/v1/statuses/' . rawurlencode($id) . '/reblog');
		$this->akkoma->react($id, self::REACTION);

		$this->assertNotNull(
			$this->here->awaitStatusThat((string)$ours['id'], static fn (array $s): bool => (int)($s['favourites_count'] ?? 0) >= 1),
			'the favourite from Akkoma was not counted'
		);
		$this->assertNotNull(
			$this->here->awaitStatusThat((string)$ours['id'], static fn (array $s): bool => (int)($s['reblogs_count'] ?? 0) === 1),
			'the boost from Akkoma was not counted'
		);
		$this->assertNotNull(
			$this->here->awaitStatusThat((string)$ours['id'], static function (array $s): bool {
				foreach (is_array($s['reactions'] ?? null) ? $s['reactions'] : [] as $reaction) {
					if (($reaction['name'] ?? '') === self::REACTION && (int)($reaction['count'] ?? 0) === 1) {
						return true;
					}
				}

				return false;
			}),
			'the emoji reaction from Akkoma is not on the post here'
		);
	}

	public function testOurDeleteRemovesThePost(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->akkoma->awaitStatus($this->adminThere, (string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'the post never reached Akkoma');

		$this->here->delete('/api/v1/statuses/' . rawurlencode((string)$ours['id']));
		$this->drainQueue();

		$this->assertTrue(
			$this->akkoma->await(fn (): ?bool => $this->akkoma->status((string)$theirCopy['id']) === null ? true : null) === true,
			'Akkoma still serves a post that was deleted here'
		);
	}

	public function testAkkomasDeleteIsHonoured(): void {
		$sent = $this->akkoma->publish($this->unique(), ['visibility' => 'public']);
		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);
		$this->assertNotNull($status, 'the post never reached this side');

		$this->akkoma->delete('/api/v1/statuses/' . rawurlencode((string)$sent['id']));

		$this->assertTrue(
			$this->here->await(fn (): ?bool => $this->here->status((string)$status['id']) === null ? true : null) === true,
			'the post deleted on Akkoma can still be read here'
		);
	}

	/** Makes `$follower` follow `$followedId`, unless it already does. */
	private function follows(ClientApi $follower, string $followedId, string $who): void {
		if ((bool)$follower->relationship($followedId)['following']) {
			return;
		}

		$follower->follow($followedId);
		$followed = $follower->await(function () use ($follower, $followedId): ?bool {
			// whichever side has to answer, ours waits in the queue
			$this->drainQueue();

			return (bool)$follower->relationship($followedId)['following'] ? true : null;
		});
		$this->assertTrue($followed === true, $who . ' asked to follow and was never accepted');
	}
}
