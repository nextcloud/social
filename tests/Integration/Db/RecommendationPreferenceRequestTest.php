<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\RecommendationFeedbackRequest;
use OCA\Social\Db\RecommendationPreferenceRequest;
use OCA\Social\Db\RecommendationSignalRequest;
use OCA\Social\Service\RecommendationPreferenceService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** Revision races, generation changes and owner separation on the real DB. */
class RecommendationPreferenceRequestTest extends TestCase {
	private RecommendationPreferenceService $preferences;
	private RecommendationPreferenceRequest $request;
	private RecommendationFeedbackRequest $feedback;
	private RecommendationSignalRequest $signals;
	private string $owner;
	private string $otherOwner;

	protected function setUp(): void {
		parent::setUp();
		$this->preferences = Server::get(RecommendationPreferenceService::class);
		$this->request = Server::get(RecommendationPreferenceRequest::class);
		$this->feedback = Server::get(RecommendationFeedbackRequest::class);
		$this->signals = Server::get(RecommendationSignalRequest::class);
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

	public function testResetPurgesExplicitFeedbackAtomicallyWithThePreferenceGeneration(): void {
		$this->preferences->get($this->owner);
		$eventId = '792b7610-e146-422c-9c87-d703412e9874';
		$this->feedback->record($this->owner, '0f6f07ce-f9d0-4a0d-90f2-9f1ebc629072', $eventId, 'topic', 'tech.apple', 'MORE_TOPIC', 2_000_000_000);
		$this->assertCount(1, $this->feedback->current($this->owner));

		$this->preferences->reset($this->owner, 0);

		$this->assertSame([], $this->feedback->current($this->owner));
	}

	public function testLearningSignalsAreIdempotentAndLateGenerationsCannotRestoreResetData(): void {
		$initial = $this->request->get($this->owner);
		$enabled = $this->request->setLearningMode($this->owner, $initial['revision'], 'on');
		self::assertNotNull($enabled);
		$generation = $enabled['generation'];
		$eventId = '7c5bc6c7-787f-4df7-a5bd-973cf942c55c';
		$signal = ['dimension' => 'topics', 'entity_key' => 'tech.apple', 'delta' => 0.35];

		$recorded = $this->signals->record($this->owner, $eventId, $generation, 'like', [$signal], null, 2_000_000_000);
		self::assertTrue($recorded['accepted']);
		self::assertFalse($recorded['duplicate']);
		$duplicate = $this->signals->record($this->owner, $eventId, $generation, 'like', [$signal], null, 2_000_000_001);
		self::assertTrue($duplicate['duplicate']);
		self::assertSame(1, count($this->signals->profile($this->owner)['topics']));

		$reset = $this->request->resetLearning($this->owner, $duplicate['profile_version']);
		self::assertNotNull($reset);
		self::assertSame([], $this->signals->profile($this->owner)['topics']);
		$late = $this->signals->record($this->owner, '5c5bc6c7-787f-4df7-a5bd-973cf942c55c', $generation, 'like', [$signal], null, 2_000_000_002);
		self::assertFalse($late['accepted']);
		self::assertSame([], $this->signals->profile($this->owner)['topics']);
	}

	public function testTurningLearningOffAndBackOnInvalidatesEventsFromTheEarlierConsentPeriod(): void {
		$initial = $this->request->get($this->owner);
		$enabled = $this->request->setLearningMode($this->owner, $initial['revision'], 'on');
		self::assertNotNull($enabled);
		$oldGeneration = $enabled['generation'];

		$disabled = $this->request->setLearningMode($this->owner, $enabled['revision'], 'off');
		self::assertNotNull($disabled);
		$resumed = $this->request->setLearningMode($this->owner, $disabled['revision'], 'on');
		self::assertNotNull($resumed);
		self::assertGreaterThan($oldGeneration, $resumed['generation']);

		$late = $this->signals->record(
			$this->owner,
			'32b22ad8-23a7-4d4b-94e4-b00def1e1953',
			$oldGeneration,
			'like',
			[['dimension' => 'topics', 'entity_key' => 'tech.apple', 'delta' => 0.35]],
			null,
			2_000_000_000,
		);
		self::assertFalse($late['accepted']);
		self::assertSame([], $this->signals->profile($this->owner)['topics']);
	}
}
