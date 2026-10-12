<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\StatusApiController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PollService;
use OCA\Social\Tests\Helper\FrozenClock;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A poll walked through its end on a frozen clock, through the client API and
 * the sweep that tells its voters.
 *
 * The end time is the moment the poll's results become final: the sweep that
 * runs on that second announces them, so a vote taken on it — from a local
 * account or arriving from another server — would change results that every
 * voter has just been told are the outcome. A second before, everything is
 * still open.
 *
 * The clock stands in the past: the sweep announces every poll on the
 * instance that ended in the window it reads, and a window in the future would
 * announce the instance's own polls before they end.
 */
class PollLifecycleTest extends ApiTestCase {
	private const LASTS = 600;

	private string $swept = '';

	protected function setUp(): void {
		parent::setUp();
		// the sweep keeps how far it got in an app value; a test standing in
		// another year must not leave the instance's sweep there
		$this->swept = Server::get(ConfigService::class)->getAppValue(ConfigService::SOCIAL_POLLS_SWEPT);
	}

	protected function tearDown(): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_POLLS_SWEPT, $this->swept);
		parent::tearDown();
	}

	protected function accountNames(): array {
		return ['alice', 'bob', 'carol', 'dave'];
	}

	/**
	 * Where the clock stands when the poll is posted: today, and places where
	 * the end time crosses a calendar edge.
	 *
	 * @return array<string, array{string}>
	 */
	public static function startingPoints(): array {
		return [
			'a week ago' => [''],
			'ending on a leap day' => ['2024-02-28T23:55:00Z'],
			'ending after a leap day' => ['2024-02-29T23:55:00Z'],
			'ending in November' => ['2025-10-31T23:55:00Z'],
			'ending in the next year' => ['2025-12-31T23:55:00Z'],
			'ending after the European autumn change' => ['2025-10-26T00:55:00Z'],
		];
	}

	#[DataProvider('startingPoints')]
	public function testAPollTakesVotesUntilItsLastSecondAndIsFinalOnItsEndTime(string $start): void {
		$clock = $this->freezeClock(($start === '') ? new FrozenClock(time() - 7 * 86400) : FrozenClock::at($start));
		$this->sweepTo($clock->getTime());
		$this->follow('bob', 'alice');
		$status = $this->publish('alice', [
			'status' => 'which one? ' . bin2hex(random_bytes(4)),
			'visibility' => 'public',
			'poll' => ['options' => ['left', 'right'], 'expires_in' => self::LASTS],
		]);
		$poll = $status->poll;
		$endsAt = $clock->getTime() + self::LASTS;
		$this->assertSame(gmdate('Y-m-d\TH:i:s', $endsAt) . '.000Z', $poll->expires_at);

		$clock->set($endsAt - 1);
		$this->assertSame(200, $this->vote('bob', $poll->id, 0)->status, 'a vote in the last second');
		$this->assertFalse($this->poll('alice', $poll->id)->expired);
		$this->sweep();
		$this->assertSame([], $this->pollNotificationsOf('bob'), 'the sweep a second early');

		$clock->set($endsAt);
		$this->assertTrue($this->poll('alice', $poll->id)->expired, 'expired on its end time');
		$refused = $this->vote('carol', $poll->id, 1);
		$this->assertSame(422, $refused->status, 'a vote on the end time: ' . json_encode($refused->body));
		$this->sweep();
		$notifications = $this->pollNotificationsOf('bob');
		$this->assertSame([$status->id], array_map('strval', array_keys($notifications)), 'the voter is told');
		$announced = $notifications[$status->id]->status->poll;
		$this->assertNotNull($announced, 'the notification carries the poll');
		$this->assertTrue($announced->expired);
		$this->assertSame([1, 0], array_map(static fn (\stdClass $option): int => $option->votes_count, $announced->options));

		$clock->advance(3600);
		$this->assertSame(422, $this->vote('dave', $poll->id, 1)->status, 'a vote an hour later');
		$this->sweep();
		$this->assertCount(1, $this->pollNotificationsOf('bob'), 'a later sweep announces it again to nobody');
		$this->assertSame([1, 0], $this->counts('alice', $poll->id), 'the results are the ones announced');
	}

	/**
	 * A vote from another server is a reply Note naming an option. One that
	 * arrives on the end time is consumed and not counted, as one a second
	 * before is counted.
	 */
	public function testAVoteFromAnotherServerIsCountedUntilTheEndTimeAndNotOnIt(): void {
		$clock = $this->freezeClock(new FrozenClock(time()));
		$poll = $this->publish('alice', [
			'status' => 'remote voters? ' . bin2hex(random_bytes(4)),
			'visibility' => 'public',
			'poll' => ['options' => ['yes', 'no'], 'expires_in' => self::LASTS],
		]);
		$endsAt = $clock->getTime() + self::LASTS;
		$pollId = $this->poll('alice', $poll->poll->id)->id;
		$uri = $poll->uri;

		$clock->set($endsAt - 1);
		$this->assertTrue($this->clocked(PollService::class)->handleIncomingVote($this->remoteVote($uri, 'yes', 'early')));
		$this->assertSame([1, 0], $this->counts('alice', $pollId));

		$clock->set($endsAt);
		$this->assertTrue($this->clocked(PollService::class)->handleIncomingVote($this->remoteVote($uri, 'no', 'late')));
		$this->assertSame([1, 0], $this->counts('alice', $pollId), 'the vote on the end time is not counted');
	}

	private function remoteVote(string $pollUri, string $option, string $who): Note {
		$note = new Note();
		$note->setId('https://votes.example/users/' . $who . '/statuses/' . bin2hex(random_bytes(6)));
		$note->setName($option);
		$note->setInReplyTo($pollUri);
		$note->setAttributedTo('https://votes.example/users/' . $who);
		$note->setLocal(false);

		return $note;
	}

	private function vote(string $as, string $pollId, int $choice): ApiResponse {
		return $this->call(StatusApiController::class, 'pollVote', ['nid' => $pollId], ['choices' => [$choice]], $as, 'POST');
	}

	private function poll(string $as, string $pollId): \stdClass {
		$response = $this->call(StatusApiController::class, 'pollGet', ['nid' => $pollId], [], $as);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		return $response->body;
	}

	/** @return int[] the votes of each option, in order */
	private function counts(string $as, string $pollId): array {
		return array_map(static fn (\stdClass $option): int => $option->votes_count, $this->poll($as, $pollId)->options);
	}

	private function sweep(): void {
		$this->clocked(PollService::class)->announceClosedPolls();
	}

	private function sweepTo(int $moment): void {
		Server::get(ConfigService::class)->setAppValue(ConfigService::SOCIAL_POLLS_SWEPT, (string)$moment);
	}

	/** @return array<string, \stdClass> the account's poll notifications by the id of their status */
	private function pollNotificationsOf(string $as): array {
		$response = $this->call(TimelineApiController::class, 'notifications', [], ['types' => ['poll']], $as);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		$notifications = [];
		foreach ($response->body as $notification) {
			$notifications[(string)$notification->status->id] = $notification;
		}

		return $notifications;
	}
}
