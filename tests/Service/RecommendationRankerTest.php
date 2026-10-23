<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\RecommendationRanker;
use OCA\Social\Service\RecommendationReRanker;
use PHPUnit\Framework\TestCase;

class RecommendationRankerTest extends TestCase {
	public function testExplicitTopicEvidenceRanksAboveNeutralCandidate(): void {
		$ranker = new RecommendationRanker();
		$ranked = $ranker->rank([
			['nid' => '2', 'topic_match' => 0.0],
			['nid' => '1', 'topic_match' => 1.0, 'freshness' => 0.9],
		]);

		$this->assertSame(['1', '2'], array_column($ranked, 'nid'));
		$this->assertSame(RecommendationRanker::ALGORITHM_VERSION, $ranked[0]['algorithm_version']);
		$this->assertGreaterThan($ranked[1]['rank_score'], $ranked[0]['rank_score']);
	}

	public function testTiesAreStableAndMissingSignalsAreNeutral(): void {
		$ranker = new RecommendationRanker();
		$ranked = $ranker->rank([
			['nid' => 'z', 'topic_match' => NAN, 'freshness' => INF],
			['nid' => 'a'],
		]);

		$this->assertSame(['a', 'z'], array_column($ranked, 'nid'));
		$this->assertSame(0.0, $ranked[1]['rank_features']['topic_match']);
		$this->assertSame(0.5, $ranked[1]['rank_features']['freshness']);
		$this->assertTrue(is_finite($ranked[1]['rank_score']));
	}

	public function testTiesUseCanonicalPostIdentityBeforeLocalDatabaseId(): void {
		$ranked = (new RecommendationRanker())->rank([
			['nid' => 'local-z', 'canonical_key' => 'ap://example.org/posts/a'],
			['nid' => 'local-a', 'canonical_key' => 'at://did:plc:example/app.bsky.feed.post/b'],
		]);

		$this->assertSame([
			'ap://example.org/posts/a',
			'at://did:plc:example/app.bsky.feed.post/b',
		], array_column($ranked, 'canonical_key'));
		$this->assertSame(['local-z', 'local-a'], array_column($ranked, 'nid'));
	}

	public function testMissingAndUnverifiedFollowFeaturesAreNeutral(): void {
		$ranked = (new RecommendationRanker())->rank([
			['nid' => 'missing'],
			['nid' => 'invalid', 'is_followed' => 0.4, 'author_affinity' => null],
		]);

		foreach ($ranked as $candidate) {
			$this->assertSame(0.0, $candidate['rank_features']['is_followed']);
			$this->assertSame(0.0, $candidate['rank_features']['author_affinity']);
		}
	}

	public function testScoreFeaturesAreClippedToTheirDocumentedDomains(): void {
		$ranked = (new RecommendationRanker())->rank([[
			'nid' => '1',
			'topic_match' => 3,
			'author_affinity' => -2,
			'format_match' => 4,
			'exposure_penalty' => 2,
		]]);

		$this->assertSame(1.0, $ranked[0]['rank_features']['topic_match']);
		$this->assertSame(0.0, $ranked[0]['rank_features']['behavioral_topic']);
		$this->assertSame(0.0, $ranked[0]['rank_features']['author_affinity']);
		$this->assertSame(1.0, $ranked[0]['rank_features']['format_match']);
		$this->assertSame(1.0, $ranked[0]['rank_features']['exposure_penalty']);
	}

	public function testBehavioralAffinityChangesTheRankWithoutChangingEligibility(): void {
		$ranked = (new RecommendationRanker())->rank([
			['nid' => 'new-interest', 'topic_match' => 0.7, 'behavioral_topic' => 1.0],
			['nid' => 'neutral', 'topic_match' => 0.7],
			['nid' => 'hidden', 'topic_match' => 1.0, 'behavioral_topic' => 1.0, 'eligible' => false],
		]);

		$this->assertSame(['new-interest', 'neutral'], array_column($ranked, 'nid'));
		$this->assertSame(1.0, $ranked[0]['rank_features']['behavioral_topic']);
	}

	public function testNoveltyFeatureGivesUnfamiliarAuthorsABoundedExplorationLift(): void {
		$ranked = (new RecommendationRanker())->rank([
			['nid' => 'familiar', 'topic_match' => 0.7, 'novelty' => 0.0],
			['nid' => 'unfamiliar', 'topic_match' => 0.7, 'novelty' => 1.0],
		]);

		$this->assertSame(['unfamiliar', 'familiar'], array_column($ranked, 'nid'));
		$this->assertSame(1.0, $ranked[0]['rank_features']['novelty']);
		$this->assertSame(0.0, $ranked[1]['rank_features']['novelty']);
	}

	public function testContentBalanceChangesRankingWeightsForFollowingAndDiscovery(): void {
		$ranker = new RecommendationRanker();
		$candidates = [
			['nid' => 'followed', 'topic_match' => 0.7, 'is_followed' => 1.0, 'novelty' => 0.0],
			['nid' => 'new-voice', 'topic_match' => 0.7, 'is_followed' => 0.0, 'novelty' => 1.0],
		];

		$this->assertSame('followed', $ranker->rank($candidates, 'following')[0]['nid']);
		$this->assertSame('new-voice', $ranker->rank($candidates, 'discovery')[0]['nid']);
		$this->assertSame(
			array_column($ranker->rank($candidates), 'nid'),
			array_column($ranker->rank($candidates, 'unsupported'), 'nid'),
			'Unknown settings safely use the balanced profile.'
		);
	}

	public function testPageAssemblyDeduplicatesAndSoftlySpreadsAuthors(): void {
		$ranked = [
			['nid' => '1', 'canonical_key' => 'same', 'rank_score' => 1.0, 'author_key' => 'alice'],
			['nid' => '2', 'canonical_key' => 'same', 'rank_score' => 0.99, 'author_key' => 'alice'],
			['nid' => '3', 'rank_score' => 0.98, 'author_key' => 'alice'],
			['nid' => '4', 'rank_score' => 0.97, 'author_key' => 'alice'],
			['nid' => '5', 'rank_score' => 0.96, 'author_key' => 'bob'],
			['nid' => '6', 'rank_score' => 0.95, 'author_key' => 'carol', 'eligible' => false],
		];

		$page = (new RecommendationReRanker())->page($ranked, 4);

		$this->assertSame(['1', '3', '5', '4'], array_column($page, 'nid'));
		$this->assertTrue($page[2]['_promoted_by_diversity'], 'the reason record can identify an actual diversity placement');
		$this->assertCount(count(array_unique(array_column($page, 'nid'))), $page);
	}

	public function testPrioritisedTopicsAreExemptFromSoftTopicConcentration(): void {
		$ranked = [];
		for ($i = 1; $i <= 10; $i++) {
			$ranked[] = ['nid' => (string)$i, 'rank_score' => 1.0 - $i / 100, 'topic_ids' => ['topic.one']];
		}
		$ranked[] = ['nid' => 'other', 'rank_score' => 0.89, 'topic_ids' => ['topic.two']];
		$ranker = new RecommendationReRanker();

		$normal = $ranker->page($ranked, 11);

		$this->assertSame('other', $normal[3]['nid'], 'automatic ranking should surface another topic after three matches');
	}

	public function testRepeatedHighScoringStoriesDoNotFillThePageWhenOtherTopicsAreAvailable(): void {
		$ranked = [];
		for ($i = 1; $i <= 30; $i++) {
			$ranked[] = [
				'nid' => 'security-story-' . $i,
				'rank_score' => 0.98 - ($i * 0.001),
				'author_key' => 'author-' . $i,
				'topic_ids' => ['technology', 'software', 'software.security'],
			];
		}
		for ($i = 1; $i <= 20; $i++) {
			$ranked[] = [
				'nid' => 'different-topic-' . $i,
				'rank_score' => 0.80 - ($i * 0.001),
				'author_key' => 'different-author-' . $i,
				'topic_ids' => ['topic.' . $i],
			];
		}

		$page = (new RecommendationReRanker())->page($ranked, 20, 'topic-diversity-regression');
		$repeatedStories = array_filter($page, static fn (array $candidate): bool => str_starts_with($candidate['nid'], 'security-story-'));

		$this->assertLessThanOrEqual(3, count($repeatedStories), 'one story cluster should not crowd out other subjects in the first page');
		$this->assertContains('different-topic-1', array_column($page, 'nid'));
	}

	public function testAHighInterestTopicCannotOverrideTheDiversityCapWhenAlternativesExist(): void {
		$ranked = [];
		for ($i = 1; $i <= 10; $i++) {
			$ranked[] = [
				'nid' => 'php-security-' . $i,
				'rank_score' => 0.99 - ($i * 0.001),
				'topic_ids' => ['software', 'software.backend'],
				'diversity_topics' => ['software.backend'],
			];
		}
		$ranked[] = [
			'nid' => 'community-story',
			'rank_score' => 0.1,
			'topic_ids' => ['community.civic', 'community'],
			'diversity_topics' => ['community.civic'],
		];

		$page = (new RecommendationReRanker())->page($ranked, 4, 'repeated-event-diversity');

		$this->assertSame(['php-security-1', 'php-security-2', 'php-security-3', 'community-story'], array_column($page, 'nid'));
	}

	public function testTopicConcentrationRelaxesWhenThereAreNoOtherTopicsToShow(): void {
		$ranked = [];
		for ($i = 1; $i <= 5; $i++) {
			$ranked[] = ['nid' => 'topic-' . $i, 'rank_score' => 0.9 - ($i / 100), 'topic_ids' => ['topic.one']];
		}

		$page = (new RecommendationReRanker())->page($ranked, 5, 'single-topic-coverage');

		$this->assertCount(5, $page);
		$this->assertSame(['topic-1', 'topic-2', 'topic-3', 'topic-4', 'topic-5'], array_column($page, 'nid'));
	}

	public function testQualifiedExplorationUsesOneStableBoundedSlot(): void {
		$ranked = [];
		for ($i = 0; $i < 14; $i++) {
			$ranked[] = [
				'nid' => 'normal-' . $i,
				'canonical_key' => 'normal-' . $i,
				'rank_score' => 0.99 - $i * 0.002,
				'author_key' => 'author-' . $i,
				'is_followed' => 0.0,
			];
		}
		$ranked[] = [
			'nid' => 'explore',
			'canonical_key' => 'at://did:example/post/explore',
			'rank_score' => 0.90,
			'author_key' => 'new-author',
			'is_followed' => 0.0,
			'topic_match' => 0.8,
			'matching_topic_ids' => ['tech.apple'],
			'novelty' => 0.9,
			'exposure_penalty' => 0.0,
		];
		for ($i = 15; $i < 24; $i++) {
			$ranked[] = [
				'nid' => 'normal-' . $i,
				'rank_score' => 0.80 - $i * 0.002,
				'author_key' => 'author-' . $i,
				'is_followed' => 0.0,
			];
		}

		$reranker = new RecommendationReRanker();
		$page = $reranker->page($ranked, 20, 'repeatable-session-seed');
		$again = $reranker->page($ranked, 20, 'repeatable-session-seed');

		$this->assertSame(array_column($page, 'nid'), array_column($again, 'nid'));
		$this->assertSame('explore', $page[9]['nid']);
		$this->assertTrue($page[9]['_exploration_slot']);
		$this->assertFalse($page[9]['_promoted_by_diversity']);
		$this->assertCount(1, array_filter($page, static fn (array $item): bool => ($item['_exploration_slot'] ?? false) === true));
		$this->assertArrayNotHasKey('_base_position', $page[9]);
	}

	public function testOfflineCompositionProfilesCanCompareFollowingAndDiscoveryWeights(): void {
		$ranked = [];
		for ($i = 0; $i < 14; $i++) {
			$ranked[] = ['nid' => 'normal-' . $i, 'rank_score' => 0.99 - $i * 0.002, 'author_key' => 'author-' . $i, 'is_followed' => 0.0];
		}
		$ranked[] = [
			'nid' => 'explore', 'rank_score' => 0.90, 'author_key' => 'new-author', 'is_followed' => 0.0,
			'topic_match' => 0.8, 'matching_topic_ids' => ['tech.apple'], 'novelty' => 0.9, 'exposure_penalty' => 0.0,
		];
		for ($i = 15; $i < 24; $i++) {
			$ranked[] = ['nid' => 'normal-' . $i, 'rank_score' => 0.80 - $i * 0.002, 'author_key' => 'author-' . $i, 'is_followed' => 0.0];
		}
		$reranker = new RecommendationReRanker();
		$following = $reranker->page($ranked, 20, 'balance-seed', 'following');
		$balanced = $reranker->page($ranked, 20, 'balance-seed', 'balanced');
		$discovery = $reranker->page($ranked, 20, 'balance-seed', 'discovery');

		$this->assertSame([], array_filter($following, static fn (array $item): bool => ($item['_exploration_slot'] ?? false) === true));
		$this->assertSame('explore', $balanced[9]['nid']);
		$this->assertSame('explore', $discovery[4]['nid']);
		$this->assertTrue($discovery[4]['_exploration_slot']);
	}

	public function testExplorationNeverSelectsFollowedUnmatchedExposedOrDistantCandidates(): void {
		foreach ([
			['is_followed' => 1.0],
			['topic_match' => 0.2],
			['novelty' => 0.2],
			['exposure_penalty' => 0.8],
			['matching_topic_ids' => []],
			['rank_score' => 0.1],
		] as $overrides) {
			$ranked = [];
			for ($i = 0; $i < 14; $i++) {
				$ranked[] = ['nid' => 'normal-' . $i, 'rank_score' => 0.99 - $i * 0.002, 'author_key' => 'author-' . $i, 'is_followed' => 0.0];
			}
			$ranked[] = array_merge([
				'nid' => 'candidate',
				'rank_score' => 0.90,
				'author_key' => 'candidate-author',
				'is_followed' => 0.0,
				'topic_match' => 0.8,
				'matching_topic_ids' => ['tech.apple'],
				'novelty' => 0.9,
				'exposure_penalty' => 0.0,
			], $overrides);

			$page = (new RecommendationReRanker())->page($ranked, 10, 'stable-seed');
			$this->assertSame([], array_filter($page, static fn (array $item): bool => ($item['_exploration_slot'] ?? false) === true));
		}
	}
}
