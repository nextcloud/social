<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use PHPUnit\Framework\TestCase;

/**
 * This app and a real Misskey, both ways, in the few things everybody does:
 * follow, post, reply, react, renote and delete.
 *
 * Misskey speaks the same ActivityPub with its own accents — a favourite is a
 * `Like` carrying the emoji (`_misskey_reaction`), a boost is a renote, and
 * its API is not Mastodon's — so what is asserted on its side is read through
 * its own API (`Misskey`), and on ours through our client API as a phone app
 * reads it.
 *
 * Our `admin` and Misskey's `interop` follow each other before every test.
 */
class MisskeyTest extends TestCase {
	use LocalSide;

	private const USER = 'admin';
	private const REACTION = '👍';

	private Misskey $misskey;
	private Here $here;
	/** our id for Misskey's `interop` */
	private string $interopHere = '';
	/** Misskey's id for our `admin` */
	private string $adminThere = '';

	protected function setUp(): void {
		$misskey = Misskey::fromEnvironment();
		if ($misskey === null) {
			$this->markTestSkipped('no Misskey to talk to: set MISSKEY_BASE_URL and MISSKEY_TOKEN');
		}

		$this->misskey = $misskey;
		$this->here = Here::forUser(self::USER);
		$this->interopHere = $this->here->resolveAccount($this->misskey->handle());
		$this->adminThere = $this->misskey->resolveUser(self::USER, $this->cloudHost());

		$this->misskeyFollowsUs();
		$this->weFollowMisskey();
	}

	public function testOurFollowIsAcceptedByMisskey(): void {
		$this->here->unfollow($this->interopHere);
		$this->drainQueue();
		$this->assertTrue(
			$this->misskey->await(fn (): ?bool => (bool)$this->misskey->relation($this->adminThere)['isFollowed'] ? null : true) === true,
			'Misskey still counts our account as a follower after the unfollow'
		);

		$this->weFollowMisskey();

		$this->assertTrue((bool)$this->misskey->relation($this->adminThere)['isFollowed'], 'Misskey does not count our account as a follower');
	}

	public function testMisskeysFollowIsAcceptedHere(): void {
		$this->misskey->call('following/delete', ['userId' => $this->adminThere]);
		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'followed_by', false),
			'the unfollow from Misskey did not end the follow here'
		);

		$this->misskeyFollowsUs();

		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'followed_by', true),
			'this side does not count Misskey\'s account as a follower'
		);
	}

	public function testOurPostArrives(): void {
		$words = $this->unique();
		$ours = $this->here->publish($words, ['visibility' => 'public']);
		$this->drainQueue();

		$note = $this->misskey->awaitNoteOf($this->adminThere, (string)$ours['uri']);

		$this->assertNotNull($note, 'the post never reached Misskey');
		$this->assertStringContainsString($words, (string)($note['text'] ?? ''));
		$this->assertSame('public', $note['visibility'] ?? '');
	}

	public function testMisskeysPostArrives(): void {
		$words = $this->unique();
		$note = $this->misskey->publish($words);

		$status = $this->here->awaitOnTimeline('home', $this->misskey->noteUri($note));

		$this->assertNotNull($status, 'the note never reached the home timeline of a follower here');
		$this->assertStringContainsString($words, (string)$status['content']);
		$this->assertSame('public', $status['visibility'] ?? '');
	}

	public function testOurReplyThreadsUnderTheMisskeyNote(): void {
		$note = $this->misskey->publish($this->unique());
		$ourCopy = $this->here->awaitOnTimeline('home', $this->misskey->noteUri($note));
		$this->assertNotNull($ourCopy, 'the note never reached this side');

		$reply = $this->here->publish($this->unique('reply'), ['visibility' => 'public', 'in_reply_to_id' => $ourCopy['id']]);
		$this->drainQueue();

		$threaded = $this->misskey->await(function () use ($note, $reply): ?array {
			foreach ($this->misskey->call('notes/children', ['noteId' => $note['id'], 'limit' => 40]) as $child) {
				if (is_array($child) && ($child['uri'] ?? '') === $reply['uri']) {
					return $child;
				}
			}

			return null;
		});
		$this->assertNotNull($threaded, 'the reply is not under the Misskey note it answers');
	}

	public function testMisskeysReplyThreadsUnderOurPost(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->misskey->awaitNoteOf($this->adminThere, (string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'the post never reached Misskey');

		$reply = $this->misskey->publish($this->unique('reply'), ['replyId' => $theirCopy['id']]);

		$threaded = $this->here->await(function () use ($ours, $reply): ?array {
			$context = $this->here->get('/api/v1/statuses/' . rawurlencode((string)$ours['id']) . '/context');

			return ClientApi::findByUri(is_array($context['descendants'] ?? null) ? $context['descendants'] : [], $this->misskey->noteUri($reply));
		});
		$this->assertNotNull($threaded, 'the reply from Misskey is not in the thread of the post it answers');
	}

	/** A favourite here is a reaction on Misskey, and it can be taken back. */
	public function testOurFavouriteIsAReactionThere(): void {
		$note = $this->misskey->publish($this->unique());
		$ourCopy = $this->here->awaitOnTimeline('home', $this->misskey->noteUri($note));
		$this->assertNotNull($ourCopy, 'the note never reached this side');

		$this->here->post('/api/v1/statuses/' . rawurlencode((string)$ourCopy['id']) . '/favourite');
		$this->drainQueue();
		$this->assertNotNull(
			$this->misskey->await(fn (): ?bool => Misskey::reactionCount($this->misskey->note((string)$note['id']) ?? []) === 1 ? true : null),
			'the favourite is not a reaction on Misskey'
		);

		$this->here->post('/api/v1/statuses/' . rawurlencode((string)$ourCopy['id']) . '/unfavourite');
		$this->drainQueue();
		$this->assertNotNull(
			$this->misskey->await(fn (): ?bool => Misskey::reactionCount($this->misskey->note((string)$note['id']) ?? []) === 0 ? true : null),
			'the favourite was taken back here and Misskey still counts the reaction'
		);
	}

	/** A reaction on Misskey arrives as a favourite or as that reaction. */
	public function testMisskeysReactionArrives(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->misskey->awaitNoteOf($this->adminThere, (string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'the post never reached Misskey');

		$this->misskey->call('notes/reactions/create', ['noteId' => $theirCopy['id'], 'reaction' => self::REACTION]);

		$this->assertNotNull(
			$this->here->awaitStatusThat((string)$ours['id'], static function (array $s): bool {
				foreach (is_array($s['reactions'] ?? null) ? $s['reactions'] : [] as $reaction) {
					if (($reaction['name'] ?? '') === self::REACTION && (int)($reaction['count'] ?? 0) === 1) {
						return true;
					}
				}

				return (int)($s['favourites_count'] ?? 0) === 1;
			}),
			'the reaction from Misskey was counted neither as a favourite nor as a reaction'
		);
	}

	public function testOurBoostIsARenoteThere(): void {
		$note = $this->misskey->publish($this->unique());
		$ourCopy = $this->here->awaitOnTimeline('home', $this->misskey->noteUri($note));
		$this->assertNotNull($ourCopy, 'the note never reached this side');

		$this->here->post('/api/v1/statuses/' . rawurlencode((string)$ourCopy['id']) . '/reblog');
		$this->drainQueue();

		$this->assertNotNull(
			$this->misskey->await(fn (): ?bool => (int)($this->misskey->note((string)$note['id'])['renoteCount'] ?? 0) === 1 ? true : null),
			'the boost is not a renote on Misskey'
		);
	}

	public function testMisskeysRenoteIsABoostHere(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->misskey->awaitNoteOf($this->adminThere, (string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'the post never reached Misskey');

		$this->misskey->call('notes/create', ['renoteId' => $theirCopy['id']]);

		$this->assertNotNull(
			$this->here->awaitStatusThat((string)$ours['id'], static fn (array $s): bool => (int)($s['reblogs_count'] ?? 0) === 1),
			'the renote from Misskey was not counted as a boost'
		);
	}

	public function testOurDeleteRemovesTheNote(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$this->drainQueue();
		$theirCopy = $this->misskey->awaitNoteOf($this->adminThere, (string)$ours['uri']);
		$this->assertNotNull($theirCopy, 'the post never reached Misskey');

		$this->here->delete('/api/v1/statuses/' . rawurlencode((string)$ours['id']));
		$this->drainQueue();

		$this->assertTrue(
			$this->misskey->await(fn (): ?bool => $this->misskey->note((string)$theirCopy['id']) === null ? true : null) === true,
			'Misskey still has a note that was deleted here'
		);
	}

	public function testMisskeysDeleteIsHonoured(): void {
		$note = $this->misskey->publish($this->unique());
		$status = $this->here->awaitOnTimeline('home', $this->misskey->noteUri($note));
		$this->assertNotNull($status, 'the note never reached this side');

		$this->misskey->call('notes/delete', ['noteId' => $note['id']]);

		$this->assertTrue(
			$this->here->await(fn (): ?bool => $this->here->status((string)$status['id']) === null ? true : null) === true,
			'the note deleted on Misskey can still be read here'
		);
	}

	// --- the harness ------------------------------------------------------

	private function misskeyFollowsUs(): void {
		if ((bool)$this->misskey->relation($this->adminThere)['isFollowing']) {
			return;
		}

		$this->misskey->call('following/create', ['userId' => $this->adminThere]);
		$followed = $this->misskey->await(function (): ?bool {
			// the Accept is ours to send: it waits in the queue
			$this->drainQueue();

			return (bool)$this->misskey->relation($this->adminThere)['isFollowing'] ? true : null;
		});
		$this->assertTrue($followed === true, 'Misskey asked to follow ' . $this->localHandle(self::USER) . ' and never got to');
	}

	private function weFollowMisskey(): void {
		if ((bool)$this->here->relationship($this->interopHere)['following']) {
			return;
		}

		$this->here->follow($this->interopHere);
		$followed = $this->here->await(function (): ?bool {
			$this->drainQueue();

			return (bool)$this->here->relationship($this->interopHere)['following'] ? true : null;
		});
		$this->assertTrue($followed === true, 'this side asked to follow ' . $this->misskey->handle() . ' and was never accepted');
	}
}
