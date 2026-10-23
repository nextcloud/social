<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use InvalidArgumentException;
use OCA\Social\Model\RecommendationFeedSnapshot;
use PHPUnit\Framework\TestCase;

class RecommendationFeedSnapshotTest extends TestCase {
	private const SECRET = '0123456789abcdef0123456789abcdef';
	private const NOW = 2_000_000_000;

	public function testSnapshotHasStablePagesAndRoundTripsItsImmutableState(): void {
		$snapshot = RecommendationFeedSnapshot::create('alice', 'for-you', 'all', 4, 2, self::NOW, ['1', '2', '3'], 900, ['3']);
		$rehydrated = RecommendationFeedSnapshot::fromArray($snapshot->toArray());

		$this->assertNotNull($rehydrated);
		$this->assertSame(['1', '2'], $rehydrated->page(0, 2));
		$this->assertSame(['3'], $rehydrated->page(2, 20));
		$this->assertSame(['3'], $rehydrated->explorationNids);
		$this->assertSame([], $rehydrated->page(4, 20));
		$this->assertSame($snapshot->toArray(), $rehydrated->toArray());
	}

	public function testSignedCursorIsBoundToViewerModeMediaAndSnapshotGeneration(): void {
		$snapshot = RecommendationFeedSnapshot::create('alice', 'for-you', 'videos', 7, 3, self::NOW, ['1', '2', '3']);
		$cursor = $snapshot->cursor(2, self::SECRET);

		$this->assertSame([
			'id' => $snapshot->id,
			'position' => 2,
			'profile_version' => 7,
			'generation' => 3,
			'expires_at' => self::NOW + 3600,
		], RecommendationFeedSnapshot::verifyCursor($cursor, self::SECRET, 'alice', 'for-you', 'videos', 7, 3, self::NOW + 1));
		$this->assertNull(RecommendationFeedSnapshot::verifyCursor($cursor, self::SECRET, 'bob', 'for-you', 'videos', 7, 3, self::NOW + 1));
		$this->assertNull(RecommendationFeedSnapshot::verifyCursor($cursor, self::SECRET, 'alice', 'for-you', 'photos', 7, 3, self::NOW + 1));
		$this->assertNull(RecommendationFeedSnapshot::verifyCursor($cursor, self::SECRET, 'alice', 'for-you', 'videos', 8, 3, self::NOW + 1));
		$this->assertNull(RecommendationFeedSnapshot::verifyCursor($cursor, self::SECRET, 'alice', 'for-you', 'videos', 7, 4, self::NOW + 1));
		$this->assertNull(RecommendationFeedSnapshot::verifyCursor($cursor, self::SECRET, 'alice', 'for-you', 'videos', 7, 3, self::NOW + 3600));
	}

	public function testTamperedMalformedAndWeaklySignedCursorsAreRejected(): void {
		$snapshot = RecommendationFeedSnapshot::create('alice', 'for-you', 'all', 0, 0, self::NOW, ['1']);
		$cursor = $snapshot->cursor(0, self::SECRET);
		$last = substr($cursor, -1);
		$tampered = substr($cursor, 0, -1) . ($last === 'A' ? 'B' : 'A');

		$this->assertNull(RecommendationFeedSnapshot::verifyCursor($tampered, self::SECRET, 'alice', 'for-you', 'all', 0, 0, self::NOW));
		$this->assertNull(RecommendationFeedSnapshot::verifyCursor('not-a-cursor', self::SECRET, 'alice', 'for-you', 'all', 0, 0, self::NOW));
		$this->assertNull(RecommendationFeedSnapshot::verifyCursor($cursor, 'short', 'alice', 'for-you', 'all', 0, 0, self::NOW));
	}

	public function testMalformedCachedIdListsAreRejectedAndCreationDeduplicates(): void {
		$snapshot = RecommendationFeedSnapshot::create('alice', 'for-you', 'all', 0, 0, self::NOW, ['1', '1', '2']);
		$this->assertSame(['1', '2'], $snapshot->rankedNids);

		$malformed = $snapshot->toArray();
		$malformed['ranked_nids'] = ['named' => '3'];
		$this->assertNull(RecommendationFeedSnapshot::fromArray($malformed));
		$malformed = $snapshot->toArray();
		$malformed['exploration_nids'] = ['outside-ranked-list'];
		$this->assertNull(RecommendationFeedSnapshot::fromArray($malformed));
		$malformed = $snapshot->toArray();
		$malformed['exploration_nids'] = null;
		$this->assertNull(RecommendationFeedSnapshot::fromArray($malformed));

		$this->expectException(InvalidArgumentException::class);
		RecommendationFeedSnapshot::create('alice', 'for-you', 'all', 0, 0, self::NOW, [['not' => 'an id']]);
	}
}
