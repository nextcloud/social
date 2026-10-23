<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\Discovery\PostDiscoveryService;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Utility\ITimeFactory;

/** Bounded local candidates plus throttled background coverage repair. */
class RecommendationCandidateService {
	public const MAX_PAGE_SIZE = 50;
	public const MIN_COVERAGE_TARGET = 15;
	private const LOOKBACK_DAYS = 30;
	private const PRIMARY_ALIAS_SCAN = 3;

	public function __construct(
		private RecommendationPreferenceService $preferences,
		private RecommendationTopicCatalog $catalog,
		private RecommendationFeatureExtractor $features,
		private StreamRequest $streams,
		private RemoteFetchQueue $remoteFetchQueue,
		private InterestService $interests,
		private ITimeFactory $timeFactory,
		private RecommendationRanker $ranker,
		private RecommendationReRanker $reRanker,
	) {
	}

	/**
	 * Read an owner's explicitly selected topic from the locally cached,
	 * discoverable corpus. If it is underfilled, request a single bounded
	 * background refresh for this topic through the existing queue.
	 *
	 * @return array{topic_id: string, count: int, requested: int, underfilled: bool, remote_refresh_queued: bool, sources: array{activitypub: int, atproto: int}, candidates: list<array<string, mixed>>}
	 */
	public function discover(Person $viewer, string $topicId, int $requested = 20): array {
		$limit = max(1, min(self::MAX_PAGE_SIZE, $requested));
		$empty = [
			'topic_id' => $topicId,
			'count' => 0,
			'requested' => $limit,
			'underfilled' => true,
			'remote_refresh_queued' => false,
			'sources' => ['activitypub' => 0, 'atproto' => 0],
			'candidates' => [],
		];
		if (!$this->preferences->isEnabled()) {
			return $empty;
		}

		$topic = $this->topic($topicId);
		if ($topic === null || !$topic['enabled'] || in_array('sensitive-topic', $topic['policyTags'], true)) {
			return $empty;
		}

		$state = $this->preferences->get($viewer->getUserId());
		$mode = $state['effective_topics'][$topicId] ?? null;
		if (!in_array($mode, ['INTERESTED', 'PRIORITISE'], true)) {
			return $empty;
		}

		$this->streams->setViewer($viewer);
		$sinceNid = Nid::fromPublishedTime(
			max(0, $this->timeFactory->getTime() - self::LOOKBACK_DAYS * 86400), 0, StreamRequest::NID_LIMIT
		);
		$aliases = $topic['aliases'];
		$posts = $this->streams->recommendationCandidatesByTerms(
			$aliases,
			$sinceNid,
			min(100, max($limit * 5, self::MIN_COVERAGE_TARGET)),
			$this->interests->hiddenFor($viewer),
			$this->interests->languagesFor($viewer->getUserId())
		);

		$candidates = [];
		foreach ($posts as $nid => $post) {
			$features = $this->features->extract($post);
			$match = $features['topics'][$topicId] ?? null;
			if ($match === null) {
				continue;
			}
			$protocol = str_starts_with($post->getId(), 'at://') ? 'atproto' : 'activitypub';
			$candidates[] = [
				'nid' => (string)$nid,
				'author_key' => $post->getActorId(),
				'topic_ids' => array_keys($features['topics']),
				'protocol' => $protocol,
				'published_at' => $post->getPublishedTime(),
				'language' => $features['language'],
				'format' => $features['format'],
				'score' => $match['score'],
				'topic_match' => $match['score'],
				'freshness' => self::freshness($post->getPublishedTime(), $this->timeFactory->getTime(), $features['format']),
				'source_reliability' => 0.5,
				'evidence' => $match['evidence'],
			];
		}
		$candidates = $this->reRanker->page($this->ranker->rank($candidates), $limit, $mode === 'PRIORITISE' ? [$topicId] : []);
		$candidates = array_map(static function (array $candidate): array {
			unset($candidate['author_key'], $candidate['topic_ids'], $candidate['eligible'], $candidate['canonical_key']);
			return $candidate;
		}, $candidates);
		$sources = ['activitypub' => 0, 'atproto' => 0];
		foreach ($candidates as $candidate) {
			$protocol = $candidate['protocol'] === 'atproto' ? 'atproto' : 'activitypub';
			$sources[$protocol]++;
		}

		$underfilled = count($candidates) < min($limit, self::MIN_COVERAGE_TARGET);
		$queued = false;
		if ($underfilled) {
			$remoteAlias = self::remoteAlias($aliases);
			if ($remoteAlias !== '') {
				// AP providers expose topic-like discovery as hashtag timelines;
				// Bluesky also supports a bounded public text search. Both calls
				// are queued and deduplicated by RemoteFetchQueue, never awaited.
				$tagQueued = $this->remoteFetchQueue->fillPosts(PostDiscoveryService::TAG, $remoteAlias, $viewer);
				$searchQueued = $this->remoteFetchQueue->fillPosts(PostDiscoveryService::SEARCH, $remoteAlias, $viewer);
				$queued = $tagQueued || $searchQueued;
			}
		}

		return [
			'topic_id' => $topicId,
			'count' => count($candidates),
			'requested' => $limit,
			'underfilled' => $underfilled,
			'remote_refresh_queued' => $queued,
			'sources' => $sources,
			'candidates' => $candidates,
		];
	}

	/** @return array{aliases: list<string>, policyTags: list<string>, enabled: bool}|null */
	private function topic(string $id): ?array {
		foreach ($this->catalog->all() as $topic) {
			if ($topic['id'] === $id) {
				return [
					'aliases' => $topic['aliases'],
					'policyTags' => $topic['policyTags'],
					'enabled' => $topic['enabled'],
				];
			}
		}

		return null;
	}

	/** Return one safe primary alias, avoiding phrase expansion into many jobs. */
	private static function remoteAlias(array $aliases): string {
		foreach (array_slice($aliases, 0, self::PRIMARY_ALIAS_SCAN) as $alias) {
			$alias = trim(mb_strtolower($alias, 'UTF-8'));
			if ($alias !== '' && preg_match('/^[\p{L}\p{N}_-]{2,64}$/u', $alias) === 1) {
				return $alias;
			}
		}

		return '';
	}

	private static function freshness(int $publishedAt, int $now, string $format): float {
		if ($publishedAt <= 0 || $now <= 0) {
			return 0.5;
		}
		$ageHours = max(0, $now - $publishedAt) / 3600;
		$halfLifeHours = $format === 'short' || $format === 'video' ? 12.0 : 24.0;

		return exp(-0.6931471805599453 * (float)$ageHours / $halfLifeHours);
	}
}
