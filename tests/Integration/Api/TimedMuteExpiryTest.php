<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Tests\Helper\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A timed mute walked through its expiry on a frozen clock.
 *
 * A mute is stored with the moment it runs out and nothing deletes it: every
 * read decides whether it still applies. These tests stand on the second
 * before that moment and on the moment itself, and ask each read a client
 * makes — the relationship, the mutes list, the home timeline — the same
 * question, because one of them disagreeing with the others is a muted
 * account that is listed as muted while its posts are back, or the reverse.
 */
class TimedMuteExpiryTest extends ApiTestCase {
	private const DURATION = 3600;

	public function testATimedMuteHoldsUntilItsLastSecondAndEndsOnTheSecondItExpires(): void {
		$clock = $this->freezeClock(new FrozenClock(time()));
		$mutedAt = $clock->getTime();
		$this->follow('alice', 'bob');
		$post = $this->publish('bob', ['status' => 'timed mute probe ' . bin2hex(random_bytes(4)), 'visibility' => 'public']);

		$muted = $this->mute('alice', 'bob', self::DURATION);
		$this->assertTrue($muted->muting);
		$this->assertSame($this->iso($mutedAt + self::DURATION), $muted->mute_expires_at);

		$clock->set($mutedAt + self::DURATION - 1);
		$this->assertMuted(true, $muted->mute_expires_at, $post->id, 'a second before the mute runs out');

		$clock->set($mutedAt + self::DURATION);
		$this->assertMuted(false, null, $post->id, 'on the second the mute runs out');

		$clock->advance(86400);
		$this->assertMuted(false, null, $post->id, 'a day after the mute ran out');
	}

	public function testMutingAgainWithoutADurationTurnsATimedMuteIntoAPermanentOne(): void {
		$clock = $this->freezeClock(new FrozenClock(time()));
		$mutedAt = $clock->getTime();
		$this->follow('alice', 'bob');
		$post = $this->publish('bob', ['status' => 'permanent mute probe ' . bin2hex(random_bytes(4)), 'visibility' => 'public']);

		$this->mute('alice', 'bob', self::DURATION);
		$permanent = $this->mute('alice', 'bob', 0);
		$this->assertNull($permanent->mute_expires_at);

		$clock->set($mutedAt + self::DURATION + 1);
		$this->assertMuted(true, null, $post->id, 'after the first expiry has passed');
	}

	/**
	 * The expiry goes through a DATETIME column and back: an instant at a
	 * calendar edge has to come out as the same instant, or a mute ends an
	 * hour early on the night the clocks change, or a day late at the end of
	 * February.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function calendarEdges(): array {
		return [
			'into a leap day' => ['2028-02-28T23:30:00Z', 3600],
			'out of a leap day' => ['2028-02-29T23:59:59Z', 1],
			'over the end of a 31-day month' => ['2026-10-31T23:00:00Z', 7200],
			'over the end of the year' => ['2026-12-31T23:59:30Z', 60],
			'over the spring change in Europe' => ['2027-03-28T00:30:00Z', 3600],
			'over the autumn change in Europe' => ['2026-10-25T00:30:00Z', 3600],
			'over the spring change in the US' => ['2027-03-14T06:30:00Z', 3600],
		];
	}

	#[DataProvider('calendarEdges')]
	public function testAnExpiryAtACalendarEdgeComesBackAsTheSameInstant(string $moment, int $duration): void {
		$clock = $this->freezeClock(FrozenClock::at($moment));
		$service = $this->clocked(AccountRelationService::class);
		$alice = $this->account('alice');
		$bob = $this->account('bob');

		$expiresAt = $service->setMuteExpiry($alice, $bob, $duration);
		$this->assertSame($clock->getTime() + $duration, $expiresAt);
		$this->assertSame([$bob->getId() => $expiresAt], $service->muteExpiries($alice->getId(), [$bob->getId()]));

		$clock->set($expiresAt - 1);
		$this->assertFalse($service->isMuteExpired($alice->getId(), $bob->getId()));
		$clock->set($expiresAt);
		$this->assertTrue($service->isMuteExpired($alice->getId(), $bob->getId()));
	}

	private function mute(string $as, string $whom, int $duration): \stdClass {
		$response = $this->call(
			AccountApiController::class, 'accountMute', ['id' => (string)$this->account($whom)->getNid()],
			['duration' => $duration], $as, 'POST'
		);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');

		return $response->body;
	}

	/** Every read alice makes about bob agrees on whether the mute holds. */
	private function assertMuted(bool $muted, ?string $expiresAt, string $postId, string $when): void {
		$relationships = $this->call(AccountApiController::class, 'relationships', [], [
			'id' => [(string)$this->account('bob')->getNid()],
		], 'alice');
		$this->assertSame($muted, $relationships->body[0]->muting, 'relationship.muting ' . $when);
		$this->assertSame($expiresAt, $relationships->body[0]->mute_expires_at, 'relationship.mute_expires_at ' . $when);

		$mutes = $this->call(AccountApiController::class, 'mutes', [], [], 'alice');
		$listed = array_map(static fn (\stdClass $account): string => $account->id, $mutes->body);
		$this->assertSame($muted, in_array((string)$this->account('bob')->getNid(), $listed, true), '/api/v1/mutes ' . $when);

		$home = $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'home'], [], 'alice');
		$ids = array_map(static fn (\stdClass $status): string => $status->id, $home->body);
		$this->assertSame(!$muted, in_array($postId, $ids, true), 'bob\'s post on alice\'s home ' . $when);
	}

	private function iso(int $time): string {
		return gmdate('Y-m-d\TH:i:s', $time) . '.000Z';
	}
}
