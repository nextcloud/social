<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\RecommendationPreferenceRequest;
use OCA\Social\Service\RecommendationPreferenceService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** Revision races, generation changes and owner separation on the real DB. */
class RecommendationPreferenceRequestTest extends TestCase {
	private RecommendationPreferenceService $preferences;
	private RecommendationPreferenceRequest $request;
	private string $owner;
	private string $otherOwner;

	protected function setUp(): void {
		parent::setUp();
		$this->preferences = Server::get(RecommendationPreferenceService::class);
		$this->request = Server::get(RecommendationPreferenceRequest::class);
		$suffix = bin2hex(random_bytes(8));
		$this->owner = 'rec-test-' . $suffix;
		$this->otherOwner = 'rec-test-other-' . $suffix;
	}

	protected function tearDown(): void {
		$this->request->purge($this->owner);
		$this->request->purge($this->otherOwner);
		parent::tearDown();
	}

	public function testConcurrentStaleWritesCannotOverwriteAndResetInvalidatesTheGeneration(): void {
		$initial = $this->preferences->get($this->owner);
		$this->assertSame(0, $initial['revision']);
		$this->assertSame(0, $initial['generation']);

		$saved = $this->preferences->replace($this->owner, 0, [
			['topic_id' => 'tech.apple', 'mode' => 'INTERESTED'],
		]);
		$this->assertNotNull($saved);
		$this->assertSame(1, $saved['revision']);
		$this->assertSame(1, $saved['generation']);
		$this->assertNull($this->preferences->replace($this->owner, 0, [
			['topic_id' => 'oss.nextcloud', 'mode' => 'PRIORITISE'],
		]));
		$this->assertSame([['topic_id' => 'tech.apple', 'mode' => 'INTERESTED']], $this->preferences->get($this->owner)['topics']);

		$reset = $this->preferences->reset($this->owner, 1);
		$this->assertNotNull($reset);
		$this->assertSame(2, $reset['revision']);
		$this->assertSame(2, $reset['generation']);
		$this->assertSame([], $reset['topics']);
		$this->assertSame($reset, $this->preferences->reset($this->owner, 1), 'repeating the same reset is idempotent');
	}

	public function testOneOwnerCannotReadOrChangeAnotherOwnersPreferences(): void {
		$this->preferences->replace($this->owner, 0, [
			['topic_id' => 'tech.apple', 'mode' => 'HIDE'],
		]);

		$this->assertSame([], $this->preferences->get($this->otherOwner)['topics']);
		$this->assertSame(0, $this->preferences->get($this->otherOwner)['revision']);
	}
}
