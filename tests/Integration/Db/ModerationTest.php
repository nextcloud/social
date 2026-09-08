<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ModerationRequest;
use OCA\Social\Model\Moderation;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The moderation table against a real database: a decision round-trips,
 * changing one's mind replaces rather than duplicates, and the two readers the
 * timelines and the inbox rely on answer correctly.
 */
class ModerationTest extends TestCase {
	private const SPAMMER = 'https://spam.example/modtest/users/spammer';
	private const NOISY = 'https://noise.example/modtest/users/noisy';

	private ModerationRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(ModerationRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::SPAMMER, self::NOISY] as $id) {
			$this->request->delete($id);
		}
	}

	public function testADecisionRoundTrips(): void {
		$this->request->save(new Moderation(self::SPAMMER, Moderation::SUSPEND, 'endless crypto'));

		$this->assertSame(Moderation::SUSPEND, $this->request->levelOf(self::SPAMMER));

		$stored = null;
		foreach ($this->request->getAll() as $decision) {
			if ($decision->getActorId() === self::SPAMMER) {
				$stored = $decision;
			}
		}

		$this->assertNotNull($stored);
		$this->assertSame('endless crypto', $stored->getComment());
		$this->assertGreaterThan(0, $stored->getCreation());
	}

	public function testChangingYourMindReplacesTheDecision(): void {
		$this->request->save(new Moderation(self::SPAMMER, Moderation::SILENCE));
		$this->request->save(new Moderation(self::SPAMMER, Moderation::SUSPEND));

		$this->assertSame(Moderation::SUSPEND, $this->request->levelOf(self::SPAMMER));
		$this->assertCount(
			1,
			array_filter(
				$this->request->getAll(),
				fn (Moderation $d): bool => $d->getActorId() === self::SPAMMER
			),
			'a second decision replaces the first rather than sitting beside it'
		);
	}

	public function testTheReadersTheTimelinesAndInboxUseAreKeptApart(): void {
		$this->request->save(new Moderation(self::NOISY, Moderation::SILENCE));
		$this->request->save(new Moderation(self::SPAMMER, Moderation::SUSPEND));

		$silenced = $this->request->getActorIdsAt(Moderation::SILENCE);

		// the public timeline hides the silenced one and never the suspended
		// one, whose posts are gone rather than hidden
		$this->assertContains(self::NOISY, $silenced);
		$this->assertNotContains(self::SPAMMER, $silenced);
	}

	public function testAnAccountNobodyHasJudgedHasNoLevel(): void {
		$this->assertSame('', $this->request->levelOf('https://fine.example/users/nobody'));
	}

	public function testLiftingLeavesNothingBehind(): void {
		$this->request->save(new Moderation(self::SPAMMER, Moderation::SUSPEND));
		$this->request->delete(self::SPAMMER);

		$this->assertSame('', $this->request->levelOf(self::SPAMMER));
	}
}
