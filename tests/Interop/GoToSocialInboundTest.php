<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use PHPUnit\Framework\TestCase;

/**
 * What a real GoToSocial sends here, read back through this app's own client
 * API.
 *
 * GoToSocial signs every delivery with a key published at a URL of its own
 * (`…/users/interop/main-key`, not the `#main-key` fragment everybody else
 * uses), so each test below that sees something arrive also proves that
 * this side found that key's owner and verified the signature with it.
 *
 * What is asserted is what our `admin` sees through the API a phone app
 * reads: the home timeline, a status, a thread, the notifications.
 */
class GoToSocialInboundTest extends TestCase {
	use GoToSocialPair;

	protected function setUp(): void {
		$this->setUpPair();
	}

	/** GoToSocial's follow is answered here, and counted on both sides. */
	public function testGoToSocialsFollowIsAccepted(): void {
		$this->gts->unfollow($this->adminThere);
		$this->drainQueue();
		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'followed_by', false),
			'the unfollow from GoToSocial did not end the follow here'
		);

		$this->gtsFollowsUs();

		$this->assertTrue(
			$this->here->awaitRelationship($this->interopHere, 'followed_by', true),
			'this side does not count GoToSocial\'s account as a follower'
		);
	}

	public function testAPostArrivesOnTheHomeTimeline(): void {
		$words = $this->unique();
		$sent = $this->gts->publish($words, ['visibility' => 'public', 'spoiler_text' => 'a warning, kept']);

		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);

		$this->assertNotNull($status, 'the post never reached the home timeline of a follower here');
		$this->assertStringContainsString($words, (string)$status['content']);
		$this->assertSame('public', $status['visibility'] ?? '');
		$this->assertSame('a warning, kept', $status['spoiler_text'] ?? '');
		$this->assertSame(strtolower($this->gts->handle()), strtolower((string)($status['account']['acct'] ?? '')));
	}

	public function testAReplyThreadsUnderOurPost(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$theirCopy = $this->awaitOnGts($ours);

		$sent = $this->gts->publish($this->unique('reply'), ['visibility' => 'public', 'in_reply_to_id' => $theirCopy['id']]);

		$reply = $this->here->await(function () use ($ours, $sent): ?array {
			$context = $this->here->get('/api/v1/statuses/' . rawurlencode((string)$ours['id']) . '/context');

			return ClientApi::findByUri(is_array($context['descendants'] ?? null) ? $context['descendants'] : [], (string)$sent['uri']);
		});
		$this->assertNotNull($reply, 'the reply from GoToSocial is not in the thread of the post it answers');
		$this->assertSame((string)$ours['id'], (string)($reply['in_reply_to_id'] ?? ''));
	}

	/**
	 * A direct message is read by the account it was written to and by nobody
	 * else: not on a public timeline, and not by another account here.
	 */
	public function testADirectMessageStaysDirect(): void {
		$sent = $this->gts->publish($this->localHandle(self::USER) . ' ' . $this->unique(), ['visibility' => 'direct']);

		$notification = $this->here->awaitNotification(
			'mention',
			static fn (array $n): bool => ($n['status']['uri'] ?? '') === $sent['uri']
		);
		$this->assertNotNull($notification, 'the direct message never reached the account it was written to');
		$this->assertSame('direct', $notification['status']['visibility'] ?? '');
		$this->assertNull(
			ClientApi::findByUri($this->here->timeline('public'), (string)$sent['uri']),
			'a direct message is on the public timeline'
		);
		$this->assertNull(
			Here::forUser(self::BYSTANDER)->status((string)$notification['status']['id']),
			'another account here can read a direct message that was not written to it'
		);
	}

	public function testAnEditIsApplied(): void {
		$words = $this->unique();
		$sent = $this->gts->publish($words, ['visibility' => 'public']);
		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);
		$this->assertNotNull($status, 'the post never reached the home timeline of a follower here');

		$this->gts->put('/api/v1/statuses/' . rawurlencode((string)$sent['id']), ['status' => $words . ' corrected']);

		$this->assertNotNull(
			$this->here->awaitStatusThat(
				(string)$status['id'],
				static fn (array $now): bool => str_contains((string)($now['content'] ?? ''), 'corrected')
			),
			'this side still shows the words that were replaced on GoToSocial'
		);
	}

	public function testADeleteRemovesThePost(): void {
		$sent = $this->gts->publish($this->unique(), ['visibility' => 'public']);
		$status = $this->here->awaitOnTimeline('home', (string)$sent['uri']);
		$this->assertNotNull($status, 'the post never reached the home timeline of a follower here');

		$this->gts->delete('/api/v1/statuses/' . rawurlencode((string)$sent['id']));

		$this->assertTrue(
			$this->here->await(fn (): ?bool => ($this->here->status((string)$status['id']) === null) ? true : null) === true,
			'the post deleted on GoToSocial can still be read here'
		);
	}

	public function testAFavouriteIsCountedAndNotified(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$theirCopy = $this->awaitOnGts($ours);

		$this->gts->post('/api/v1/statuses/' . rawurlencode((string)$theirCopy['id']) . '/favourite');

		$this->assertNotNull(
			$this->here->awaitStatusThat((string)$ours['id'], static fn (array $s): bool => (int)($s['favourites_count'] ?? 0) === 1),
			'the favourite from GoToSocial was not counted'
		);
		$this->assertNotNull(
			$this->here->awaitNotification('favourite', static fn (array $n): bool => (string)($n['status']['id'] ?? '') === (string)$ours['id']),
			'the favourite raised no notification'
		);
	}

	public function testABoostIsCountedAndNotified(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$theirCopy = $this->awaitOnGts($ours);

		$this->gts->post('/api/v1/statuses/' . rawurlencode((string)$theirCopy['id']) . '/reblog');

		$this->assertNotNull(
			$this->here->awaitStatusThat((string)$ours['id'], static fn (array $s): bool => (int)($s['reblogs_count'] ?? 0) === 1),
			'the boost from GoToSocial was not counted'
		);
		$this->assertNotNull(
			$this->here->awaitNotification('reblog', static fn (array $n): bool => (string)($n['status']['id'] ?? '') === (string)$ours['id']),
			'the boost raised no notification'
		);
	}

	/**
	 * GoToSocial reads the policy our posts publish and applies it to its own
	 * users before anything is sent: a favourite and a boost of a post that
	 * leaves both open go through, and GoToSocial does not hold them back as
	 * requests that need an answer from here.
	 */
	public function testOurOpenPostIsFavouritedWithoutAskingUs(): void {
		$ours = $this->here->publish($this->unique(), ['visibility' => 'public']);
		$theirCopy = $this->awaitOnGts($ours);
		$this->assertContains('public', $theirCopy['interaction_policy']['can_favourite']['automatic_approval'] ?? [], 'GoToSocial does not read our post as open to favourites');

		$favourited = $this->gts->post('/api/v1/statuses/' . rawurlencode((string)$theirCopy['id']) . '/favourite');

		$this->assertTrue((bool)($favourited['favourited'] ?? false), 'GoToSocial holds the favourite of an open post as a request');
		$this->assertNotNull(
			$this->here->awaitStatusThat((string)$ours['id'], static fn (array $s): bool => (int)($s['favourites_count'] ?? 0) === 1),
			'the favourite was not counted here'
		);
	}
}
