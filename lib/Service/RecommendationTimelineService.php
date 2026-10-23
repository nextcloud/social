<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\Discovery\PostDiscoveryService;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Utility\ITimeFactory;

/** Serves one automatically personalized ranker through the web For You timeline contract. */
class RecommendationTimelineService {
	private const MAX_CANDIDATES = 100;
	private const MAX_TERMS = 20;
	private const MAX_REFRESH_TOPICS = 2;
	private const LOOKBACK_DAYS = RecommendationFreshness::LOOKBACK_DAYS;
	private const MIN_COVERAGE = 15;
	private const MODE = 'for-you-v4-automatic-profile-4';
	private const MEDIA = ['', 'photos', 'videos', 'shorts', 'media'];

	public function __construct(
		private RecommendationPreferenceService $preferences,
		private RecommendationTopicCatalog $catalog,
		private RecommendationFeatureExtractor $features,
		private StreamRequest $streams,
		private InterestService $interests,
		private InterestFeedService $legacyFeed,
		private RecommendationRanker $ranker,
		private RecommendationReRanker $reRanker,
		private RecommendationSnapshotStore $snapshots,
		private RecommendationExplanationService $explanations,
		private RecommendationFeedbackService $feedbackService,
		private RecommendationBehavioralProfile $behavioralProfile,
		private RecommendationFollowFeatures $followFeatures,
		private RecommendationTrendCandidates $trendCandidates,
		private RecommendationSocialGraphCandidates $socialGraphCandidates,
		private RecommendationSavedFeedCandidates $savedFeedCandidates,
		private RemoteFetchQueue $remoteFetchQueue,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * One ranker blends automatically learned hashtag and behavior signals with
	 * content features, follows and freshness. Manual topic choices do not
	 * configure or filter the automatic web feed.
	 * Mobile API clients keep using the unchanged `/timelines/interests` contract.
	 *
	 * @return array{items: list<Stream>, session_id: ?string, next_cursor: ?string, mode: string, algorithm_version: ?int, profile_version: int, cursor_expired: bool, coverage: array{state: string, known_eligible: ?int, returned: int, remote_refresh_queued: bool}}
	 */
	public function page(Person $viewer, int $requested, string $cursor = '', string $media = ''): array {
		$limit = max(1, min(RecommendationCandidateService::MAX_PAGE_SIZE, $requested));
		$media = in_array($media, self::MEDIA, true) ? $media : '';
		$state = $this->preferences->get($viewer->getUserId());
		if ($this->preferences->forceLegacyFeed()) {
			if ($cursor === '' || str_starts_with($cursor, 'legacy.')) {
				$legacyCursor = $cursor === '' ? '0' : $this->decodeLegacyCursor($cursor, $state['revision'], $state['generation']);
				return $legacyCursor === null
					? $this->empty($state['revision'], true, 'LEGACY')
					: $this->legacyPage($viewer, $limit, $legacyCursor, $media, $state['revision'], $state['generation']);
			}
			// A ranked snapshot cursor cannot be continued against a different source.
			return $this->empty($state['revision'], true, 'LEGACY');
		}
		// Persisted legacy rows still provide revision/generation values for
		// consent and cursor invalidation. Their topic fields never set the feed.
		if ($cursor !== '' && str_starts_with($cursor, 'legacy.')) {
			$legacyCursor = $this->decodeLegacyCursor($cursor, $state['revision'], $state['generation']);
			if ($legacyCursor === null) {
				return $this->empty($state['revision'], true, 'LEGACY');
			}
			return $this->legacyPage($viewer, $limit, $legacyCursor, $media, $state['revision'], $state['generation']);
		}

		$this->streams->setViewer($viewer);
		$feedback = $this->feedbackService->current($viewer->getUserId());
		$behavioral = $this->behavioralProfile->forViewer($viewer);
		$candidateTopics = $this->candidateTopics($behavioral['topics']);
		$now = $this->timeFactory->getTime();
		if ($cursor !== '') {
			return $this->continuePage($viewer, $limit, $cursor, $media, $state, $candidateTopics, $feedback, $behavioral, $now);
		}

		$sinceNid = Nid::fromPublishedTime(max(0, $now - self::LOOKBACK_DAYS * 86400), 0, StreamRequest::NID_LIMIT);
		$terms = $this->terms($candidateTopics, $now);
		$candidateBudget = min(self::MAX_CANDIDATES, max($limit * 5, self::MIN_COVERAGE));
		$automaticBudget = min(20, max(4, intdiv($candidateBudget, 5)));
		$followBudget = min(20, max(4, intdiv($candidateBudget + 4, 5)));
		$followBudget = min($followBudget, $candidateBudget);
		$explorationBudget = min(10, max(1, intdiv($candidateBudget, 10)));
		$explorationBudget = min($explorationBudget, $candidateBudget - $followBudget);
		$trendBudget = min(RecommendationTrendCandidates::MAX_CANDIDATES, max(1, intdiv($candidateBudget, 10)));
		$trendBudget = min($trendBudget, $candidateBudget - $followBudget - $explorationBudget);
		$socialGraphBudget = min(RecommendationSocialGraphCandidates::MAX_CANDIDATES, max(1, intdiv($candidateBudget, 10)));
		$socialGraphBudget = min($socialGraphBudget, $candidateBudget - $followBudget - $explorationBudget - $trendBudget);
		$savedFeedBudget = min(RecommendationSavedFeedCandidates::MAX_CANDIDATES, max(1, intdiv($candidateBudget, 10)));
		$savedFeedBudget = min($savedFeedBudget, $candidateBudget - $followBudget - $explorationBudget - $trendBudget - $socialGraphBudget);
		$hiddenNids = array_values(array_unique(array_merge(
			$this->interests->hiddenFor($viewer),
			$feedback['hidden_nids']
		)));
		$languages = $this->interests->languagesFor($viewer->getUserId());
		$trendPosts = $this->trendCandidates->forViewer(
			$viewer,
			$trendBudget,
			$hiddenNids,
			$languages,
			array_column($candidateTopics, 'id'),
			[],
			$media,
		);
		$hiddenTopicIds = [];
		$socialGraphPosts = $this->socialGraphCandidates->matching(
			$sinceNid,
			$socialGraphBudget,
			array_values(array_unique(array_merge($hiddenNids, array_keys($trendPosts)))),
			$languages,
			array_column($candidateTopics, 'id'),
			$hiddenTopicIds,
			$media,
		);
		$savedFeedPosts = $this->savedFeedCandidates->matching(
			$viewer,
			$savedFeedBudget,
			array_values(array_unique(array_merge($hiddenNids, array_keys($trendPosts), array_keys($socialGraphPosts)))),
			$languages,
			array_column($candidateTopics, 'id'),
			$hiddenTopicIds,
			$media,
		);
		$topicBudget = max(0, $candidateBudget - $automaticBudget - $followBudget - $explorationBudget - count($trendPosts) - count($socialGraphPosts) - count($savedFeedPosts));
		$posts = $terms === [] ? [] : $this->streams->recommendationCandidatesByTerms(
			$terms, $sinceNid, $topicBudget, $hiddenNids, $languages, $now
		);
		// Keep a small independent source for followed authors. This query uses
		// the accepted-follow relation directly, so it still finds posts when
		// the asynchronous word index has not yet caught up.
		$followCandidates = $this->streams->recommendationFollowCandidates(
			$sinceNid,
			$followBudget,
			array_values(array_unique(array_merge($hiddenNids, array_keys($posts)))),
			$languages
		);
		$posts += $followCandidates;
		$posts += $this->streams->recommendationExplorationCandidates(
			$sinceNid,
			$explorationBudget,
			intdiv($now, 6 * 3600),
			array_values(array_unique(array_merge($hiddenNids, array_keys($posts)))),
			$languages
		);
		$posts += $trendPosts;
		$posts += $socialGraphPosts;
		$posts += $savedFeedPosts;
		$automaticPosts = $this->interests->isEnabled()
			? $this->legacyFeed->page($viewer, $automaticBudget, '0', 0, $media)
			: [];
		foreach ($automaticPosts as $automaticPost) {
			$nid = (string)$automaticPost->getNid();
			if ($nid !== '' && !isset($posts[$nid])) {
				$posts[$nid] = $automaticPost;
			}
		}
		$automaticSignals = $this->interests->isEnabled()
			? $this->legacyFeed->recommendationSignals($viewer, array_values($posts))
			: [];
		$followedAuthors = $this->followFeatures->followedAuthors($viewer, array_values($posts));
		$exposurePenalties = $this->behavioralProfile->exposurePenalties($viewer, array_values($posts));

		$candidates = [];
		foreach ($posts as $nid => $post) {
			$candidate = $this->candidate($post, (string)$nid, $candidateTopics, $media, $now, $feedback, $behavioral, $followedAuthors, $exposurePenalties, $automaticSignals);
			if ($candidate !== null) {
				$candidates[] = $candidate;
			}
		}
		$contentBalance = 'balanced';
		$ranked = $this->reRanker->page($this->ranker->rank($candidates, $contentBalance), self::MAX_CANDIDATES, bin2hex(random_bytes(16)), $contentBalance);
		$explorationNids = array_values(array_map(
			static fn (array $candidate): string => (string)$candidate['nid'],
			array_filter($ranked, static fn (array $candidate): bool => ($candidate['_exploration_slot'] ?? false) === true)
		));
		$explanationEntries = [];
		foreach ($ranked as $candidate) {
			$explanationEntries[] = [
				'nid' => (string)$candidate['nid'],
				'reason' => $this->explanations->explain($candidate, $state['revision'], $now),
			];
		}
		$snapshot = $this->snapshots->create(
			$viewer->getUserId(), self::MODE, $media, $state['revision'], $state['generation'], $now,
			array_column($ranked, 'nid'), $limit, $explorationNids, $explanationEntries
		);

		if ($snapshot === null) {
			$selected = array_slice($ranked, 0, $limit);
			$sessionId = null;
			$nextCursor = null;
		} else {
			$selected = self::byNids($ranked, $snapshot['nids']);
			$sessionId = $snapshot['session_id'];
			$nextCursor = $snapshot['next_cursor'];
		}

		$items = $this->hydrate(
			$posts,
			$selected,
			$candidateTopics,
			$snapshot['exploration_nids'] ?? $explorationNids,
			$snapshot['explanations'] ?? $explanationEntries,
		);
		$underfilled = count($ranked) < min($limit, self::MIN_COVERAGE);
		$queued = $underfilled && !$this->preferences->remoteDiscoveryPaused()
			? $this->queueCoverage($candidateTopics, $viewer, $now)
			: false;

		return [
			'items' => $items,
			'session_id' => $sessionId,
			'next_cursor' => $nextCursor,
			'mode' => 'RANKED',
			'algorithm_version' => RecommendationRanker::ALGORITHM_VERSION,
			'profile_version' => $state['revision'],
			'cursor_expired' => false,
			'coverage' => [
				'state' => $underfilled ? 'UNDERFILLED' : 'GOOD',
				'known_eligible' => count($ranked),
				'returned' => count($items),
				'remote_refresh_queued' => $queued,
			],
		];
	}

	/** @param array<string, float> $behavioral @return list<array{id: string, mode: string, aliases: list<string>, labels: array{en: string, de: string}}> */
	private function candidateTopics(array $behavioral): array {
		$topics = [];
		arsort($behavioral, SORT_NUMERIC);
		foreach ($behavioral as $topicId => $affinity) {
			if ($affinity <= 0.0) {
				continue;
			}
			$topic = $this->catalog->find($topicId);
			if ($topic === null || !$topic['enabled'] || in_array('sensitive-topic', $topic['policyTags'], true)) {
				continue;
			}
			$topics[] = ['id' => $topicId, 'mode' => 'AUTOMATIC', 'aliases' => $topic['aliases'], 'labels' => $topic['labels']];
			if (count($topics) >= 20) {
				break;
			}
		}
		return $topics;
	}

	/** @param list<array{id: string, mode: string, aliases: list<string>, labels: array{en: string, de: string}}> $topics @return list<string> */
	private function terms(array $topics, int $now): array {
		$aliasesByTopic = [];
		foreach ($topics as $topic) {
			$aliases = array_values(array_unique(array_filter(
				array_map('trim', $topic['aliases']),
				static fn (string $alias): bool => $alias !== ''
			)));
			if ($aliases !== []) {
				$aliasesByTopic[] = $aliases;
			}
		}

		// Keep indexed lookup bounded, while rotating the primary alias window so
		// large topic selections are all searched over successive hourly refreshes.
		$count = count($aliasesByTopic);
		if ($count === 0) {
			return [];
		}
		$start = (intdiv($now, 3600) * self::MAX_TERMS) % $count;
		$terms = [];
		for ($offset = 0; $offset < $count && count($terms) < self::MAX_TERMS; $offset++) {
			$aliases = $aliasesByTopic[($start + $offset) % $count];
			if (!in_array($aliases[0], $terms, true)) {
				$terms[] = $aliases[0];
			}
		}
		// Use remaining capacity for alternate spellings after each topic has had
		// a primary-alias opportunity in the rotating window.
		for ($aliasIndex = 1; $aliasIndex < self::MAX_TERMS && count($terms) < self::MAX_TERMS; $aliasIndex++) {
			for ($offset = 0; $offset < $count && count($terms) < self::MAX_TERMS; $offset++) {
				$aliases = $aliasesByTopic[($start + $offset) % $count];
				if (isset($aliases[$aliasIndex]) && !in_array($aliases[$aliasIndex], $terms, true)) {
					$terms[] = $aliases[$aliasIndex];
				}
			}
		}

		return $terms;
	}

	/** @param array<string, mixed> $feedback @return array<string, mixed>|null */
	private function candidate(Stream $post, string $nid, array $topics, string $media, int $now, array $feedback, array $behavioral, array $followedAuthors, array $exposurePenalties, array $automaticSignals): ?array {
		$features = $this->features->extract($post);
		$allTopicIds = array_keys($features['topics']);
		$selected = [];
		foreach ($topics as $topic) {
			if (isset($features['topics'][$topic['id']])) {
				$selected[$topic['id']] = $topic;
			}
		}
		if (!self::matchesMedia($features['format'], $media)) {
			return null;
		}
		$bestMatch = 0.0;
		$evidence = '';
		foreach ($selected as $topicId => $topic) {
			$match = $features['topics'][$topicId];
			$operation = $feedback['topics'][$topicId] ?? null;
			$score = (float)$match['score'];
			if ($operation === 'MORE_TOPIC') {
				$score = min(1.0, $score + 0.28);
			} elseif ($operation === 'LESS_TOPIC') {
				$score = max(-1.0, $score - 0.55);
			}
			if ($score > $bestMatch) {
				$bestMatch = $score;
				$evidence = (string)$match['evidence'];
			}
		}

		$format = $features['format'];
		$author = $post->getAttributedTo();
		$isFollowed = isset($followedAuthors[$author]);
		$exposurePenalty = $exposurePenalties[$nid] ?? 0.0;
		$authorAffinity = $this->behavioralProfile->authorAffinity($author, $behavioral['authors']);
		$behavioralTopic = $this->behavioralProfile->topicAffinity($allTopicIds, $behavioral['topics']);
		$automaticInterest = $automaticSignals[$nid] ?? 0.0;
		if ($selected === [] && $automaticInterest <= 0.0 && $behavioralTopic <= 0.0 && $authorAffinity <= 0.0 && !$isFollowed && $allTopicIds === []) {
			return null;
		}
		$authorFeedback = $author === '' ? null : ($feedback['authors'][hash('sha256', $author)] ?? null);
		$formatFeedback = $feedback['formats'][$format] ?? null;
		$clusterKey = RecommendationContentCluster::key($allTopicIds, $format, $this->catalog);
		$similarityFeedback = $clusterKey === null ? null : ($feedback['similarities'][$clusterKey] ?? null);
		$published = $post->getPublishedTime();
		return [
			'nid' => $nid,
			'canonical_key' => $post->getId() !== '' ? $post->getId() : $nid,
			'author_key' => $author,
			'topic_ids' => $allTopicIds,
			'matching_topic_ids' => array_keys($selected),
			'manual_topic_match' => count(array_filter($selected, static fn (array $topic): bool => $topic['mode'] !== 'AUTOMATIC')) > 0,
			'protocol' => RecommendationProtocol::of($post),
			'published_at' => $published,
			'format' => $format,
			'topic_match' => $bestMatch,
			'automatic_interest' => $automaticInterest,
			'behavioral_topic' => $behavioralTopic,
			'behavioral_author' => $authorAffinity,
			'behavioral_format' => $this->behavioralProfile->formatAffinity($format, $behavioral['formats']),
			'is_followed' => $isFollowed ? 1.0 : 0.0,
			'novelty' => RecommendationBehavioralProfile::novelty($authorAffinity, $isFollowed, $exposurePenalty),
			'freshness' => RecommendationFreshness::score($post, $now, $format),
			'author_affinity' => $authorFeedback === 'MORE_AUTHOR' ? 1.0 : 0.0,
			'exposure_penalty' => $exposurePenalty,
			'format_match' => $formatFeedback === 'MORE_FORMAT' ? 1.0 : 0.0,
			'feedback_alignment' => match ($similarityFeedback) {
				'MORE_SIMILAR' => 1.0,
				'LESS_SIMILAR' => -1.0,
				default => 0.0,
			},
			'source_reliability' => 0.5,
			'evidence' => $evidence,
			'rank_score' => 0.0,
		];
	}

	private static function matchesMedia(string $format, string $media): bool {
		return match ($media) {
			'photos' => $format === 'image',
			'videos' => in_array($format, ['video', 'short'], true),
			'shorts' => $format === 'short',
			'media' => in_array($format, ['image', 'video', 'short'], true),
			default => true,
		};
	}

	/** @param list<array<string, mixed>> $ranked @param list<string> $nids @return list<array<string, mixed>> */
	private static function byNids(array $ranked, array $nids): array {
		$byNid = [];
		foreach ($ranked as $candidate) {
			$byNid[$candidate['nid']] = $candidate;
		}
		return array_values(array_filter(array_map(static fn (string $nid): ?array => $byNid[$nid] ?? null, $nids)));
	}

	/** @param array<string, Stream> $posts @param list<array<string, mixed>> $candidates @param list<array{id: string, mode: string, aliases: list<string>, labels: array{en: string, de: string}}> $topics @param list<string> $explorationNids @param list<array{nid: string, reason: array<string, mixed>}> $explanationEntries @return list<Stream> */
	private function hydrate(array $posts, array $candidates, array $topics, array $explorationNids = [], array $explanationEntries = []): array {
		$byId = [];
		foreach ($topics as $topic) {
			$byId[$topic['id']] = $topic;
		}
		$explanationsByNid = [];
		foreach ($explanationEntries as $entry) {
			$explanationsByNid[$entry['nid']] = $entry['reason'];
		}
		$items = [];
		foreach ($candidates as $candidate) {
			$post = $posts[$candidate['nid']] ?? null;
			if (!$post instanceof Stream) {
				continue;
			}
			/** @var list<array{id: string, labels: array{en: string, de: string}}> $matched */
			$matched = [];
			foreach ($candidate['matching_topic_ids'] as $topicId) {
				if (isset($byId[$topicId])) {
					$matched[] = ['id' => $topicId, 'labels' => $byId[$topicId]['labels']];
				}
			}
			$isExploration = ($candidate['_exploration_slot'] ?? false) === true
				|| in_array((string)$candidate['nid'], $explorationNids, true);
			$interestReason = $isExploration
				? 'exploration'
				: ((bool)($candidate['manual_topic_match'] ?? false)
					? 'topic'
					: (($candidate['automatic_interest'] ?? 0.0) > 0.0 || ($candidate['behavioral_topic'] ?? 0.0) > 0.0 || ($candidate['behavioral_author'] ?? 0.0) > 0.0
						? 'automatic'
						: ((float)($candidate['is_followed'] ?? 0.0) === 1.0 ? 'followed_author' : 'discovery')));
			$explanation = $explanationsByNid[(string)$candidate['nid']]
				?? $this->explanations->explain($candidate, 0, 0);
			$post->setExportFormat(ACore::FORMAT_LOCAL)->setInterest([
				'tags' => [],
				'reason' => $interestReason,
				'topics' => $matched,
				'selection_stage' => 'RERANK',
				'explanation' => $explanation,
			]);
			$items[] = $post;
		}
		return $items;
	}

	/** @param list<array{id: string, mode: string, aliases: list<string>, labels: array{en: string, de: string}}> $topics */
	private function queueCoverage(array $topics, Person $viewer, int $now): bool {
		if ($topics === []) {
			if (!$this->interests->isEnabled()) {
				return false;
			}
			$profile = $this->interests->feedProfile($viewer);
			$tags = array_slice(array_keys(array_filter($profile['weights'], static fn (float $weight): bool => $weight > 0.0)), 0, self::MAX_REFRESH_TOPICS);
			$queued = false;
			foreach ($tags as $tag) {
				$queued = $this->remoteFetchQueue->fillPosts(PostDiscoveryService::TAG, $tag, $viewer) || $queued;
				$queued = $this->remoteFetchQueue->fillPosts(PostDiscoveryService::SEARCH, $tag, $viewer) || $queued;
			}
			return $queued;
		}

		// Rotate the bounded refresh window with the same interval used to
		// throttle each remote query, so later selected topics are not starved.
		$start = (intdiv(max(0, $now), RemoteFetchQueue::POSTS_INTERVAL) * self::MAX_REFRESH_TOPICS) % count($topics);
		$queued = false;
		$refreshCount = min(self::MAX_REFRESH_TOPICS, count($topics));
		for ($offset = 0; $offset < $refreshCount; $offset++) {
			$topic = $topics[($start + $offset) % count($topics)];
			$search = self::searchAlias($topic['aliases']);
			if ($search !== '') {
				$queued = $this->remoteFetchQueue->fillPosts(PostDiscoveryService::SEARCH, $search, $viewer) || $queued;
			}
			$tag = self::tagAlias($topic['aliases']);
			if ($tag !== '') {
				$queued = $this->remoteFetchQueue->fillPosts(PostDiscoveryService::TAG, $tag, $viewer) || $queued;
			}
		}
		return $queued;
	}

	/** @param list<string> $aliases */
	private static function searchAlias(array $aliases): string {
		foreach ($aliases as $alias) {
			$alias = trim(mb_strtolower($alias, 'UTF-8'));
			if ($alias !== '' && mb_strlen($alias, 'UTF-8') <= 200) {
				return $alias;
			}
		}
		return '';
	}

	/** @param list<string> $aliases */
	private static function tagAlias(array $aliases): string {
		foreach ($aliases as $alias) {
			$alias = trim(mb_strtolower($alias, 'UTF-8'));
			if (preg_match('/^[\p{L}\p{N}_-]{2,64}$/u', $alias) === 1) {
				return $alias;
			}
		}
		return '';
	}

	/** @param array<string, mixed> $state @param list<array{id: string, mode: string, aliases: list<string>, labels: array{en: string, de: string}}> $candidateTopics @param array<string, mixed> $feedback */
	private function continuePage(Person $viewer, int $limit, string $cursor, string $media, array $state, array $candidateTopics, array $feedback, array $behavioral, int $now): array {
		$page = $this->snapshots->nextPage($cursor, $viewer->getUserId(), self::MODE, $media, $state['revision'], $state['generation'], $now, $limit);
		if ($page === null) {
			return $this->empty($state['revision'], true, 'RANKED');
		}

		$items = [];
		$nextCursor = $page['next_cursor'];
		$sessionId = $page['session_id'];
		for ($scan = 0; $scan < 5; $scan++) {
			$posts = $this->streams->getVisibleByNids($page['nids']);
			$followedAuthors = $this->followFeatures->followedAuthors($viewer, array_values($posts));
			$exposurePenalties = $this->behavioralProfile->exposurePenalties($viewer, array_values($posts));
			$automaticSignals = $this->interests->isEnabled()
				? $this->legacyFeed->recommendationSignals($viewer, array_values($posts))
				: [];
			$ordered = [];
			foreach ($page['nids'] as $nid) {
				$post = $posts[$nid] ?? null;
				if (!$post instanceof Stream || in_array($nid, $feedback['hidden_nids'], true)) {
					continue;
				}
				$candidate = $this->candidate($post, $nid, $candidateTopics, $media, $now, $feedback, $behavioral, $followedAuthors, $exposurePenalties, $automaticSignals);
				if ($candidate !== null) {
					if (in_array($nid, $page['exploration_nids'] ?? [], true)) {
						$candidate['_exploration_slot'] = true;
					}
					$ordered[] = $candidate;
				}
			}
			$items = array_merge($items, $this->hydrate($posts, $ordered, $candidateTopics, $page['exploration_nids'] ?? [], $page['explanations'] ?? []));
			if (count($items) >= $limit || $nextCursor === null) {
				break;
			}
			$page = $this->snapshots->nextPage($nextCursor, $viewer->getUserId(), self::MODE, $media, $state['revision'], $state['generation'], $now, $limit);
			if ($page === null) {
				$nextCursor = null;
				break;
			}
			$nextCursor = $page['next_cursor'];
		}

		return [
			'items' => array_slice($items, 0, $limit),
			'session_id' => $sessionId,
			'next_cursor' => $nextCursor,
			'mode' => 'RANKED',
			'algorithm_version' => RecommendationRanker::ALGORITHM_VERSION,
			'profile_version' => $state['revision'],
			'cursor_expired' => false,
			'coverage' => ['state' => 'GOOD', 'known_eligible' => null, 'returned' => count($items), 'remote_refresh_queued' => false],
		];
	}

	/** @return array{items: list<Stream>, session_id: ?string, next_cursor: ?string, mode: string, algorithm_version: ?int, profile_version: int, cursor_expired: bool, coverage: array{state: string, known_eligible: int, returned: int, remote_refresh_queued: bool}} */
	private function legacyPage(Person $viewer, int $limit, string $maxId, string $media, int $revision, int $generation): array {
		$items = $this->legacyFeed->page($viewer, $limit, $maxId, 0, $media);
		$nextCursor = null;
		if (count($items) >= $limit && $items !== []) {
			$last = $items[count($items) - 1];
			$nextCursor = self::legacyCursor($revision, $generation, (string)$last->getNid());
		}
		return [
			'items' => $items,
			'session_id' => null,
			'next_cursor' => $nextCursor,
			'mode' => 'LEGACY',
			'algorithm_version' => null,
			'profile_version' => $revision,
			'cursor_expired' => false,
			'coverage' => ['state' => 'LEGACY', 'known_eligible' => count($items), 'returned' => count($items), 'remote_refresh_queued' => false],
		];
	}

	private static function legacyCursor(int $revision, int $generation, string $nid): string {
		$encoded = rtrim(strtr(base64_encode($nid), '+/', '-_'), '=');
		return 'legacy.' . $revision . '.' . $generation . '.' . $encoded;
	}

	private function decodeLegacyCursor(string $cursor, int $revision, int $generation): ?string {
		if (preg_match('/^legacy\.(\d{1,12})\.(\d{1,12})\.([A-Za-z0-9_-]{2,40})$/', $cursor, $match) !== 1
			|| (int)$match[1] !== $revision
			|| (int)$match[2] !== $generation) {
			return null;
		}
		$decoded = base64_decode(strtr($match[3], '-_', '+/') . str_repeat('=', (4 - strlen($match[3]) % 4) % 4), true);
		return is_string($decoded) && preg_match('/^\d{1,20}$/', $decoded) === 1 ? $decoded : null;
	}

	/** @return array{items: list<Stream>, session_id: null, next_cursor: null, mode: string, algorithm_version: int, profile_version: int, cursor_expired: bool, coverage: array{state: string, known_eligible: int, returned: int, remote_refresh_queued: bool}} */
	private function empty(int $revision, bool $expired, string $mode): array {
		return [
			'items' => [], 'session_id' => null, 'next_cursor' => null, 'mode' => $mode,
			'algorithm_version' => RecommendationRanker::ALGORITHM_VERSION, 'profile_version' => $revision,
			'cursor_expired' => $expired,
			'coverage' => ['state' => $expired ? 'CURSOR_EXPIRED' : 'UNDERFILLED', 'known_eligible' => 0, 'returned' => 0, 'remote_refresh_queued' => false],
		];
	}
}
