<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use PHPUnit\Framework\TestCase;

/**
 * What this app sends, read back out of a real GoToSocial.
 *
 * GoToSocial is the strictest common peer there is: every inbox POST and every
 * fetch must carry an HTTP signature its verifier accepts, and it fetches our
 * actor and objects with signed GETs and nothing else. So every assertion
 * below that something "arrived" is also an assertion that our signature, our
 * `Digest` and our actor document passed that verifier.
 *
 * Written through this app's own client API, as a phone app writes.
 */
class GoToSocialDeliveryTest extends TestCase {
	use GoToSocialPair;

	protected function setUp(): void {
		$this->setUpPair();
	}

	public function testOurFollowIsAcceptedByGoToSocial(): void {
		$this->here->unfollow($this->interopHere);
		$this->drainQueue();
		$this->assertTrue(
			$this->gts->awaitRelationship($this->adminThere, 'followed_by', false),
			'GoToSocial still counts our account as a follower after the unfollow'
		);

		$this->here->follow($this->interopHere);
		$this->drainQueue();

		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'following', true),
			'GoToSocial\'s Accept never turned the follow into a follow here'
		);
		$this->assertTrue(
			$this->gts->awaitRelationship($this->adminThere, 'followed_by', true),
			'GoToSocial does not count our account as a follower'
		);
	}

	/** Every GoToSocial account starts locked: a follow waits for its owner's yes. */
	public function testAFollowOfALockedAccountWaitsForItsAnswer(): void {
		$guarded = GoToSocial::fromEnvironment('GOTOSOCIAL_TOKEN_GUARDED', 'guarded');
		if ($guarded === null) {
			$this->markTestSkipped('no token for GoToSocial\'s guarded: set GOTOSOCIAL_TOKEN_GUARDED');
		}
		$guardedHere = $this->here->resolveAccount($guarded->handle());
		$adminThereForGuarded = $guarded->resolveAccount($this->localHandle(self::USER));
		if ((bool)$this->here->relationship($guardedHere)['following'] || (bool)$this->here->relationship($guardedHere)['requested']) {
			$this->here->unfollow($guardedHere);
			$this->drainQueue();
			$guarded->awaitRelationship($adminThereForGuarded, 'followed_by', false);
		}

		$asked = $this->here->follow($guardedHere);
		$this->drainQueue();
		$this->assertFalse((bool)($asked['following'] ?? true), 'a locked account counted as followed before it answered');

		$request = $guarded->await(function () use ($guarded, $adminThereForGuarded): ?array {
			foreach ($guarded->get('/api/v1/follow_requests') as $account) {
				if (is_array($account) && (string)($account['id'] ?? '') === $adminThereForGuarded) {
					return $account;
				}
			}

			return null;
		});
		$this->assertNotNull($request, 'the follow request never reached the locked GoToSocial account');
		$this->assertTrue((bool)$this->here->relationship($guardedHere)['requested'], 'the follow is not shown as asked for here');

		$guarded->post('/api/v1/follow_requests/' . rawurlencode($adminThereForGuarded) . '/authorize');

		$this->assertTrue(
			$this->here->awaitRelationship($guardedHere, 'following', true),
			'the locked account said yes and this side never heard it'
		);
	}

	public function testAPublicPostArrives(): void {
		$words = $this->unique();
		$ours = $this->here->publish($words, ['visibility' => 'public']);

		$theirs = $this->awaitOnGts($ours);

		$this->assertStringContainsString($words, (string)$theirs['content']);
		$this->assertSame('public', $theirs['visibility'] ?? '');
		$this->assertNotNull(
			ClientApi::findByUri($this->gts->timeline('home'), (string)$ours['uri']),
			'the post is not on the home timeline of the follower on GoToSocial'
		);
	}

	public function testAContentWarningArrivesAsOne(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public', 'spoiler_text' => 'a warning, kept']);

		$theirs = $this->awaitOnGts($ours);

		$this->assertSame('a warning, kept', $theirs['spoiler_text'] ?? '');
	}

	public function testOurReplyThreadsUnderTheGoToSocialPost(): void {
		[$theirs, $ourCopy] = $this->gtsPostsAndWeHoldIt($this->unique());

		$reply = $this->here->publish($this->unique('reply'), ['visibility' => 'public', 'in_reply_to_id' => $ourCopy['id']]);
		$this->drainQueue();

		$threaded = $this->gts->await(
			fn (): ?array => ClientApi::findByUri($this->gts->descendants((string)$theirs['id']), (string)$reply['uri'])
		);
		$this->assertNotNull($threaded, 'the reply is not in the GoToSocial thread it answers');
		$this->assertSame((string)$theirs['id'], (string)($threaded['in_reply_to_id'] ?? ''));
	}

	/** A direct message reaches the account it names, as a direct message. */
	public function testADirectMessageReachesItsAddressee(): void {
		$ours = $this->here->publish('@' . $this->gts->handle() . ' ' . $this->unique(), ['visibility' => 'direct']);
		$this->drainQueue();

		$notification = $this->gts->awaitNotification(
			'mention',
			static fn (array $n): bool => ($n['status']['uri'] ?? '') === $ours['uri']
		);
		$this->assertNotNull($notification, 'the direct message never reached the account it was written to');
		$this->assertSame('direct', $notification['status']['visibility'] ?? '');
		$this->assertNull(
			ClientApi::findByUri($this->gts->timeline('public'), (string)$ours['uri']),
			'the direct message is on GoToSocial\'s public timeline'
		);
	}

	/** An edit has to carry `updated`, or GoToSocial keeps the words that were replaced. */
	public function testAnEditArrivesAsAnEdit(): void {
		$ours = $this->here->publish($this->unique('before'), ['visibility' => 'public']);
		$theirs = $this->awaitOnGts($ours);

		$after = $this->unique('after');
		$this->here->put('/api/v1/statuses/' . rawurlencode((string)$ours['id']), ['status' => $after]);
		$this->drainQueue();

		$edited = $this->gts->awaitStatusThat(
			(string)$theirs['id'],
			static fn (array $s): bool => str_contains((string)($s['content'] ?? ''), $after)
		);
		$this->assertNotNull($edited, 'GoToSocial still shows the words from before the edit');
		$this->assertNotEmpty($edited['edited_at'] ?? null, 'GoToSocial does not mark the post as edited');
	}

	public function testADeleteTakesThePostAway(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$theirs = $this->awaitOnGts($ours);

		$this->here->delete('/api/v1/statuses/' . rawurlencode((string)$ours['id']));
		$this->drainQueue();

		$this->assertTrue(
			$this->gts->await(fn (): ?bool => $this->gts->status((string)$theirs['id']) === null ? true : null) === true,
			'GoToSocial still serves a post that was deleted here'
		);
	}

	/** A favourite and a boost of a GoToSocial post, counted there, and undone. */
	public function testOurFavouriteAndBoostAreCountedThereAndCanBeUndone(): void {
		[$theirs, $ourCopy] = $this->gtsPostsAndWeHoldIt($this->unique());
		$id = rawurlencode((string)$ourCopy['id']);
		$counts = fn (string $field, int $wanted): ?array => $this->gts->awaitStatusThat(
			(string)$theirs['id'],
			static fn (array $s): bool => (int)($s[$field] ?? -1) === $wanted
		);

		$this->here->post('/api/v1/statuses/' . $id . '/favourite');
		$this->drainQueue();
		$this->assertNotNull($counts('favourites_count', 1), 'the favourite was not counted on GoToSocial');

		$this->here->post('/api/v1/statuses/' . $id . '/reblog');
		$this->drainQueue();
		$this->assertNotNull($counts('reblogs_count', 1), 'the boost was not counted on GoToSocial');

		$this->here->post('/api/v1/statuses/' . $id . '/unreblog');
		$this->drainQueue();
		$this->assertNotNull($counts('reblogs_count', 0), 'the boost was undone here and still counts on GoToSocial');

		$this->here->post('/api/v1/statuses/' . $id . '/unfavourite');
		$this->drainQueue();
		$this->assertNotNull($counts('favourites_count', 0), 'the favourite was undone here and still counts on GoToSocial');
	}

	/**
	 * Our reply rule is GoToSocial's `canReply`, and GoToSocial holds its own
	 * users to it: a post here that nobody may answer is one GoToSocial will
	 * not let `interop` answer either, and says so in the policy it shows.
	 */
	public function testGoToSocialHoldsItsUsersToOurReplyRule(): void {
		$ours = $this->here->publish($this->unique('nobody answers'), ['visibility' => 'public', 'reply_policy' => 'nobody']);
		$theirs = $this->awaitOnGts($ours);

		$canReply = $theirs['interaction_policy']['can_reply'] ?? null;
		$this->assertIsArray($canReply, 'GoToSocial shows no reply policy on our post: ' . json_encode($theirs['interaction_policy'] ?? null));
		$this->assertNotContains('public', $canReply['automatic_approval'] ?? [], 'GoToSocial reads our "nobody" as "anybody"');
		$this->assertNotContains('public', $canReply['manual_approval'] ?? [], 'GoToSocial offers to ask for approval of a reply our rule refuses outright');

		$refused = null;
		try {
			$this->gts->publish($this->unique('reply anyway'), ['visibility' => 'public', 'in_reply_to_id' => $theirs['id']]);
		} catch (\RuntimeException $e) {
			$refused = $e->getMessage();
		}
		$this->assertNotNull($refused, 'GoToSocial let its user answer a post whose author said nobody may');
		$this->assertMatchesRegularExpression('/ 40[03]: /', $refused, 'GoToSocial refused the reply for another reason: ' . $refused);
	}

	/**
	 * A post anybody may favourite and boost on GoToSocial says so in its
	 * policy, and this side offers both; one whose author keeps replies to
	 * themself is shown here as not answerable, and a reply to it is refused
	 * here with the reason rather than sent to be dropped.
	 */
	public function testAGoToSocialReplyPolicyIsHonouredHere(): void {
		[$theirs, $ourCopy] = $this->gtsPostsAndWeHoldIt($this->unique('no replies'), [
			// every clause, because GoToSocial reads one left out as "the author only"
			'interaction_policy' => [
				'can_favourite' => ['automatic_approval' => ['public']],
				'can_reblog' => ['automatic_approval' => ['public']],
				'can_reply' => ['automatic_approval' => ['author']],
			],
		]);

		$policy = $ourCopy['interaction_policy'] ?? null;
		$this->assertIsArray($policy, 'the policy GoToSocial published is not shown to clients here');
		$this->assertFalse((bool)($policy['reply'] ?? true), 'a post its author keeps replies to is offered for replies here');
		$this->assertTrue((bool)($policy['like'] ?? false), 'a post anybody may favourite is not offered for favourites here');

		$refused = null;
		try {
			$this->here->publish($this->unique('reply anyway'), ['visibility' => 'public', 'in_reply_to_id' => $ourCopy['id']]);
		} catch (\RuntimeException $e) {
			$refused = $e->getMessage();
		}
		$this->assertNotNull($refused, 'a reply the author does not allow was written anyway');
		$this->assertStringContainsString('does not allow replies', $refused);
		$this->drainQueue();
		$this->assertSame([], $this->gts->descendants((string)$theirs['id']), 'GoToSocial was sent a reply its author does not allow');
	}

	/**
	 * GoToSocial addresses a direct message's interaction policy to the
	 * accounts it mentions — `canReply` names them, and only them besides the
	 * author. Being named there is permission: the addressee here may answer,
	 * and GoToSocial threads the answer without asking anybody.
	 */
	public function testTheAddresseeOfAGoToSocialDirectMessageMayAnswerIt(): void {
		$theirs = $this->gts->publish('@' . ltrim($this->localHandle(self::USER), '@') . ' ' . $this->unique('dm'), ['visibility' => 'direct']);
		$ourCopy = $this->here->awaitNotification(
			'mention',
			static fn (array $n): bool => ($n['status']['uri'] ?? '') === $theirs['uri']
		)['status'] ?? null;
		$this->assertIsArray($ourCopy, 'the direct message never reached its addressee here');
		$this->assertTrue((bool)($ourCopy['interaction_policy']['reply'] ?? true), 'the addressee is not offered a reply to a message written to them');

		$reply = $this->here->publish($this->unique('answer'), ['visibility' => 'direct', 'in_reply_to_id' => $ourCopy['id']]);
		$this->drainQueue();

		$threaded = $this->gts->await(
			fn (): ?array => ClientApi::findByUri($this->gts->descendants((string)$theirs['id']), (string)$reply['uri'])
		);
		$this->assertNotNull($threaded, 'the answer to the direct message is not threaded under it on GoToSocial');
	}

	/**
	 * GoToSocial lets the followers of the author of a followers-only post
	 * favourite it — `canLike` names the followers collection. GoToSocial
	 * cannot be asked politely by this side, so the favourite is sent as it
	 * is, GoToSocial approves it on its own because we follow, and tells us
	 * so with an `Accept` naming a `LikeAuthorization`; the favourite counts
	 * there and nothing here breaks on the `Accept`.
	 */
	public function testAFollowersFavouriteOfAFollowersOnlyPostIsApprovedByGoToSocial(): void {
		$theirs = $this->gts->publish($this->unique('followers only'), ['visibility' => 'private']);
		$ourCopy = $this->here->awaitOnTimeline('home', (string)$theirs['uri']);
		$this->assertNotNull($ourCopy, 'the followers-only post never reached its follower here');
		$this->assertTrue((bool)($ourCopy['interaction_policy']['like'] ?? true), 'a follower is not offered a favourite the author lets followers give');

		$this->here->post('/api/v1/statuses/' . rawurlencode((string)$ourCopy['id']) . '/favourite');
		$this->drainQueue();

		$this->assertNotNull(
			$this->gts->awaitStatusThat((string)$theirs['id'], static fn (array $s): bool => (int)($s['favourites_count'] ?? 0) === 1),
			'GoToSocial did not count the favourite of a follower'
		);
		$this->assertSame([], $this->gts->interactionRequests(), 'GoToSocial holds the follower\'s favourite for approval instead of approving it');
		$this->drainQueue();
		$this->assertTrue((bool)($this->here->status((string)$ourCopy['id'])['favourited'] ?? false), 'the Accept from GoToSocial undid the favourite here');
	}
}
