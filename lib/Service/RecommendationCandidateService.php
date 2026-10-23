<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
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
		private RecommendationSnapshotStore $snapshotStore,
		private RecommendationFeedbackService $feedbackService,
		private RecommendationFollowFeatures $followFeatures,
		private RecommendationBehavioralProfile $behavioralProfile,
	) {
	}

	/**
	 * Read an owner's explicitly selected topic from the locally cached,
	 * discoverable corpus. If it is underfilled, request a single bounded
	 * background refresh for this topic through the existing queue.
	 *
	 * @return array{topic_id: string, count: int, requested: int, underfilled: bool, remote_refresh_queued: bool, sources: array{activitypub: int, atproto: int}, session_id: ?string, next_cursor: ?string, cursor_expired: bool, candidates: list<array<string, mixed>>}
	 */
	public function discover(Person $viewer, string $topicId, int $requested = 20, string $cursor = ''): array {
		$limit = max(1, min(self::MAX_PAGE_SIZE, $requested));
		$empty = [
			'topic_id' => $topicId,
			'count' => 0,
			'requested' => $limit,
			'underfilled' => true,
			'remote_refresh_queued' => false,
			'sources' => ['activitypub' => 0, 'atproto' => 0],
			'session_id' => null,
			'next_cursor' => null,
			'cursor_expired' => false,
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
		$feedback = $this->feedbackService->current($viewer->getUserId());
		if ($cursor !== '') {
			return $this->continueSnapshot($viewer, $topicId, $mode, $state['revision'], $state['generation'], $limit, $cursor, $empty, $feedback);
		}

		$now = $this->timeFactory->getTime();
		$sinceNid = Nid::fromPublishedTime(
			max(0, $now - self::LOOKBACK_DAYS * 86400), 0, StreamRequest::NID_LIMIT
		);
		$aliases = $topic['aliases'];
		$posts = $this->streams->recommendationCandidatesByTerms(
			$aliases,
			$sinceNid,
			min(100, max($limit * 5, self::MIN_COVERAGE_TARGET)),
			array_values(array_unique(array_merge($this->interests->hiddenFor($viewer), $feedback['hidden_nids']))),
			$this->interests->languagesFor($viewer->getUserId())
		);
		$followedAuthors = $this->followFeatures->followedAuthors($viewer, array_values($posts));
		$exposurePenalties = $this->behavioralProfile->exposurePenalties($viewer, array_values($posts));
		$behavioralAuthors = $this->behavioralProfile->forViewer($viewer)['authors'];

		$candidates = [];
		foreach ($posts as $nid => $post) {
			$candidate = $this->candidate($post, (string)$nid, $topicId, $now, $feedback, $followedAuthors, $exposurePenalties, $behavioralAuthors);
			if ($candidate !== null) {
				$candidates[] = $candidate;
			}
		}
		$ranked = $this->reRanker->page($this->ranker->rank($candidates), 100, $mode === 'PRIORITISE' ? [$topicId] : [], bin2hex(random_bytes(16)));
		$explorationNids = array_values(array_map(
			static fn (array $candidate): string => (string)$candidate['nid'],
			array_filter($ranked, static fn (array $candidate): bool => ($candidate['_exploration_slot'] ?? false) === true)
		));
		$snapshot = $this->snapshotStore->create(
			$viewer->getUserId(), 'topic:' . $topicId, 'all', $state['revision'], $state['generation'], $now,
			array_column($ranked, 'nid'), $limit, $explorationNids
		);
		if ($snapshot === null) {
			$candidates = $this->reRanker->page($ranked, $limit);
			$nextCursor = null;
			$sessionId = null;
		} else {
			$sessionId = $snapshot['session_id'];
			$nextCursor = $snapshot['next_cursor'];
			$byNid = [];
			foreach ($ranked as $candidate) {
				$byNid[$candidate['nid']] = $candidate;
			}
			$candidates = array_values(array_filter(array_map(
				static fn (string $nid): ?array => $byNid[$nid] ?? null,
				$snapshot['nids']
			)));
		}
		$candidates = self::publicCandidates($candidates);
		$sources = self::sourceCounts($candidates);

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
			'session_id' => $sessionId,
			'next_cursor' => $nextCursor,
			'cursor_expired' => false,
			'candidates' => $candidates,
		];
	}

	/** @param array<string, mixed> $empty @return array<string, mixed> */
	private function continueSnapshot(Person $viewer, string $topicId, string $mode, int $revision, int $generation, int $limit, string $cursor, array $empty, array $feedback): array {
		$now = $this->timeFactory->getTime();
		$page = $this->snapshotStore->nextPage($cursor, $viewer->getUserId(), 'topic:' . $topicId, 'all', $revision, $generation, $now, $limit);
		if ($page === null) {
			$empty['cursor_expired'] = true;
			return $empty;
		}

		$candidates = [];
		$behavioralAuthors = $this->behavioralProfile->forViewer($viewer)['authors'];
		$nextCursor = $page['next_cursor'];
		$sessionId = $page['session_id'];
		for ($scan = 0; $scan < 5; $scan++) {
			$visible = $this->streams->getVisibleByNids($page['nids']);
			$followedAuthors = $this->followFeatures->followedAuthors($viewer, array_values($visible));
			$exposurePenalties = $this->behavioralProfile->exposurePenalties($viewer, array_values($visible));
			$orderedNids = $page['nids'];
			foreach ($orderedNids as $nid) {
				if (in_array($nid, $feedback['hidden_nids'], true)) {
					continue;
				}
				$post = $visible[$nid] ?? null;
				if ($post === null) {
					continue;
				}
				$candidate = $this->candidate($post, $nid, $topicId, $now, $feedback, $followedAuthors, $exposurePenalties, $behavioralAuthors);
				if ($candidate !== null) {
					$candidates[] = $candidate;
					if (count($candidates) >= $limit) {
						break 2;
					}
				}
			}
			if ($nextCursor === null) {
				break;
			}
			$page = $this->snapshotStore->nextPage($nextCursor, $viewer->getUserId(), 'topic:' . $topicId, 'all', $revision, $generation, $now, $limit);
			if ($page === null) {
				break;
			}
			$nextCursor = $page['next_cursor'];
		}

		$rankedByNid = [];
		foreach ($this->ranker->rank($candidates) as $candidate) {
			$rankedByNid[$candidate['nid']] = $candidate;
		}
		$public = self::publicCandidates(array_values(array_filter(array_map(
			static fn (string $nid): ?array => $rankedByNid[$nid] ?? null,
			array_column($candidates, 'nid')
		))));

		return [
			'topic_id' => $topicId,
			'count' => count($public),
			'requested' => $limit,
			'underfilled' => count($public) < min($limit, self::MIN_COVERAGE_TARGET),
			'remote_refresh_queued' => false,
			'sources' => self::sourceCounts($public),
			'session_id' => $sessionId,
			'next_cursor' => $nextCursor,
			'cursor_expired' => false,
			'candidates' => $public,
		];
	}

	/** @return array<string, mixed>|null */
	private function candidate(Stream $post, string $nid, string $topicId, int $now, array $feedback, array $followedAuthors, array $exposurePenalties, array $behavioralAuthors): ?array {
		$features = $this->features->extract($post);
		$match = $features['topics'][$topicId] ?? null;
		if ($match === null) {
			return null;
		}
		$protocol = str_starts_with($post->getId(), 'at://') ? 'atproto' : 'activitypub';
		$author = $post->getAttributedTo();
		$isFollowed = isset($followedAuthors[$author]);
		$authorAffinity = $this->behavioralProfile->authorAffinity($author, $behavioralAuthors);
		$exposurePenalty = $exposurePenalties[$nid] ?? 0.0;

		$candidate = [
			'nid' => $nid,
			'author_key' => $author,
			'topic_ids' => array_keys($features['topics']),
			'protocol' => $protocol,
			'canonical_key' => $post->getId() !== '' ? $post->getId() : $nid,
			'published_at' => $post->getPublishedTime(),
			'language' => $features['language'],
			'format' => $features['format'],
			'score' => $match['score'],
			'topic_match' => $match['score'],
			'is_followed' => $isFollowed ? 1.0 : 0.0,
			'behavioral_author' => $authorAffinity,
			'novelty' => RecommendationBehavioralProfile::novelty($authorAffinity, $isFollowed, $exposurePenalty),
			'exposure_penalty' => $exposurePenalty,
			'freshness' => self::freshness($post->getPublishedTime(), $now, $features['format']),
			'source_reliability' => 0.5,
			'evidence' => $match['evidence'],
		];
		$candidate = $this->feedbackService->apply($candidate, $topicId, $feedback);
		unset($candidate['feedback_applied']);
		return $candidate;
	}

	/** @param list<array<string, mixed>> $candidates @return list<array<string, mixed>> */
	private static function publicCandidates(array $candidates): array {
		return array_map(static function (array $candidate): array {
			unset($candidate['author_key'], $candidate['topic_ids'], $candidate['eligible'], $candidate['canonical_key'], $candidate['_base_position'], $candidate['_exploration_slot']);
			return $candidate;
		}, $candidates);
	}

	/** @param list<array<string, mixed>> $candidates @return array{activitypub: int, atproto: int} */
	private static function sourceCounts(array $candidates): array {
		$sources = ['activitypub' => 0, 'atproto' => 0];
		foreach ($candidates as $candidate) {
			$protocol = $candidate['protocol'] === 'atproto' ? 'atproto' : 'activitypub';
			$sources[$protocol]++;
		}

		return $sources;
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
