<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\StarterPackModeration;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/** Which accounts a starter pack may offer, against this instance's moderation. */
#[AllowMockObjectsWithoutExpectations]
class StarterPackModerationTest extends TestCase {
	private const OK = 'https://fine.example/users/ok';
	private const SUSPENDED = 'https://fine.example/users/suspended';
	private const SILENCED = 'https://fine.example/users/silenced';
	private const ON_BLOCKED_HOST = 'https://blocked.example/users/someone';
	private const ON_SILENCED_HOST = 'https://sub.quiet.example/users/someone';
	private const BLUESKY = 'https://bsky.app/profile/did:plc:fine';
	private const BLUESKY_BLOCKED = 'https://bsky.app/profile/did:plc:blocked';

	private function moderation(): StarterPackModeration {
		$fediverse = $this->createStub(FediverseService::class);
		$fediverse->method('authorized')->willReturnCallback(static function (string $host): bool {
			if ($host === 'blocked.example' || $host === 'bsky.app') {
				throw new UnauthorizedFediverseException($host);
			}

			return true;
		});
		$fediverse->method('silencedHosts')->willReturn(['quiet.example' => true]);

		$decisions = $this->createStub(ModerationRequest::class);
		$decisions->method('amongAt')->willReturnCallback(static fn (array $ids, string $level): array => array_fill_keys(
			array_intersect($ids, [Moderation::SUSPEND => [self::SUSPENDED], Moderation::SILENCE => [self::SILENCED]][$level]),
			true
		));

		$blocklist = $this->createStub(Blocklist::class);
		$blocklist->method('isBlockedDid')->willReturnCallback(static fn (string $did): bool => $did === 'did:plc:blocked');
		$blocklist->method('isBlockedActor')->willReturn(false);

		return new StarterPackModeration($fediverse, $decisions, $blocklist);
	}

	public function testOnlyTheAccountsNoDecisionKeepsOutAreAdmittedInTheirOrder(): void {
		$this->assertSame(
			[self::BLUESKY, self::OK],
			$this->moderation()->admitted([
				self::BLUESKY, self::SUSPENDED, self::ON_BLOCKED_HOST, self::OK,
				self::SILENCED, self::ON_SILENCED_HOST, self::BLUESKY_BLOCKED,
			])
		);
	}

	public function testABlueskyAccountItsLabelsLimitIsNotAdmitted(): void {
		$limited = new Person();
		$limited->setId(self::BLUESKY);
		$limited->setDetailArray(ActorMapper::DETAIL, ['limited' => true]);
		$ok = new Person();
		$ok->setId(self::OK);

		$this->assertSame([$ok], $this->moderation()->admittedActors([$limited, $ok]));
		$this->assertFalse($this->moderation()->admits($limited));
	}
}
