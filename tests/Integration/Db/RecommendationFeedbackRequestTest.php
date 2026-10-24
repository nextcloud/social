<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use InvalidArgumentException;
use OCA\Social\Db\RecommendationFeedbackRequest;
use OCA\Social\Db\RecommendationPreferenceRequest;
use OCA\Social\Service\RecommendationPreferenceService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** Owner isolation, idempotency, exact Undo and storage bounds on the real DB. */
class RecommendationFeedbackRequestTest extends TestCase {
	private RecommendationFeedbackRequest $feedback;
	private RecommendationPreferenceRequest $preferencesRequest;
	private RecommendationPreferenceService $preferences;
	private string $owner;

	protected function setUp(): void {
		parent::setUp();
		$this->feedback = Server::get(RecommendationFeedbackRequest::class);
		$this->preferencesRequest = Server::get(RecommendationPreferenceRequest::class);
		$this->preferences = Server::get(RecommendationPreferenceService::class);
		$this->owner = 'rec-feedback-' . bin2hex(random_bytes(8));
		$this->preferences->get($this->owner);
	}

	protected function tearDown(): void {
		$this->preferencesRequest->purge($this->owner);
		parent::tearDown();
	}

	public function testFeedbackIsIdempotentUndoRestoresThePreviousScopeAndIsSafeToRepeat(): void {
		$now = 2_000_000_000;
		$first = $this->feedback->record($this->owner, self::uuid(1), self::uuid(101), 'similarity', 'technology|text', 'MORE_SIMILAR', $now);
		$duplicate = $this->feedback->record($this->owner, self::uuid(2), self::uuid(101), 'similarity', 'technology|text', 'MORE_SIMILAR', $now + 1);
		$this->assertTrue($duplicate['duplicate']);
		$this->assertSame($first['record']['feedback_id'], $duplicate['record']['feedback_id']);
		$this->assertSame(1, $duplicate['feedback_version']);

		$second = $this->feedback->record($this->owner, self::uuid(3), self::uuid(102), 'similarity', 'technology|text', 'LESS_SIMILAR', $now + 2);
		$this->assertSame('LESS_SIMILAR', $this->feedback->current($this->owner)["similarity\0technology|text"]['operation']);

		$undone = $this->feedback->undo($this->owner, $second['record']['feedback_id'], $now + 3);
		$this->assertTrue($undone['undone']);
		$this->assertFalse($undone['expired']);
		$this->assertSame('MORE_SIMILAR', $this->feedback->current($this->owner)["similarity\0technology|text"]['operation']);
		$this->assertTrue($this->feedback->undo($this->owner, $second['record']['feedback_id'], $now + 4)['undone']);
	}

	public function testActiveScopesAreCappedAndExpiredUndoDoesNotChangeTheAction(): void {
		$now = 2_000_000_000;
		for ($index = 1; $index <= 50; $index++) {
			$this->feedback->record($this->owner, self::uuid($index), self::uuid(1000 + $index), 'post', (string)$index, 'HIDE_POST', $now);
		}
		try {
			$this->feedback->record($this->owner, self::uuid(51), self::uuid(1051), 'post', '51', 'HIDE_POST', $now);
			$this->fail('A 51st active scope must be rejected');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('50 active feedback scopes', $e->getMessage());
		}

		$current = $this->feedback->current($this->owner);
		$this->assertCount(50, $current);
		$first = $this->feedback->byEvent($this->owner, self::uuid(1001));
		self::assertNotNull($first);
		$expired = $this->feedback->undo($this->owner, $first['feedback_id'], $now + 601);
		$this->assertFalse($expired['undone']);
		$this->assertTrue($expired['expired']);
		$this->assertCount(50, $this->feedback->current($this->owner));
	}

	private static function uuid(int $value): string {
		return sprintf('00000000-0000-4000-8000-%012x', $value);
	}
}
