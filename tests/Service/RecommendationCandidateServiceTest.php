<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\RecommendationSignalRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\Discovery\PostDiscoveryService;
use OCA\Social\Service\InterestService;
use OCA\Social\Service\RecommendationBehavioralProfile;
use OCA\Social\Service\RecommendationCandidateService;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationFeedbackService;
use OCA\Social\Service\RecommendationFollowFeatures;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationRanker;
use OCA\Social\Service\RecommendationReRanker;
use OCA\Social\Service\RecommendationSignalService;
use OCA\Social\Service\RecommendationSnapshotStore;
use OCA\Social\Service\RecommendationTopicCatalog;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class RecommendationCandidateServiceTest extends TestCase {
	private RecommendationPreferenceService $preferences;
	private StreamRequest $streams;
	private RemoteFetchQueue $queue;
	private InterestService $interests;
	private ITimeFactory $clock;
	private RecommendationSnapshotStore $snapshots;
	private RecommendationFeedbackService $feedback;
	private FollowsRequest $follows;
	private RecommendationSignalRequest $signals;

	/** @return list<string> */
	private function appleAliases(): array {
		return (new RecommendationTopicCatalog())->find('tech.apple')['aliases'] ?? [];
	}

	protected function setUp(): void {
		$this->preferences = $this->createMock(RecommendationPreferenceService::class);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->queue = $this->createMock(RemoteFetchQueue::class);
		$this->interests = $this->createMock(InterestService::class);
		$this->clock = $this->createStub(ITimeFactory::class);
		$this->clock->method('getTime')->willReturn(2_000_000_000);
		$this->snapshots = $this->createMock(RecommendationSnapshotStore::class);
		$this->feedback = $this->createMock(RecommendationFeedbackService::class);
		$this->feedback->method('current')->willReturn(['hidden_nids' => [], 'topics' => [], 'authors' => [], 'formats' => []]);
		$this->feedback->method('apply')->willReturnCallback(static fn (array $candidate, string $topicId, array $state): array => $candidate);
		$this->follows = $this->createMock(FollowsRequest::class);
		$this->follows->method('getBetweenMany')->willReturn(['following' => [], 'followedBy' => []]);
		$this->signals = $this->createMock(RecommendationSignalRequest::class);
	}

	private function service(): RecommendationCandidateService {
		return new RecommendationCandidateService(
			$this->preferences,
			new RecommendationTopicCatalog(),
			new RecommendationFeatureExtractor(new RecommendationTopicCatalog()),
			$this->streams,
			$this->queue,
			$this->interests,
			$this->clock,
			new RecommendationRanker(),
			new RecommendationReRanker(),
			$this->snapshots,
			$this->feedback,
			new RecommendationFollowFeatures($this->follows),
			new RecommendationBehavioralProfile(new RecommendationSignalService(
				$this->preferences,
				$this->signals,
				new RecommendationFeatureExtractor(new RecommendationTopicCatalog()),
				$this->streams,
				$this->clock,
			), new RecommendationTopicCatalog()),
		);
	}

	private function viewer(): Person {
		$person = new Person();
		$person->setId('https://example.test/users/alice')->setUserId('alice')->setLocal(true);
		return $person;
	}

	public function testDisabledDiscoveryDoesNotReadPreferencesOrQueueNetworkWork(): void {
		$this->preferences->expects($this->once())->method('isEnabled')->willReturn(false);
		$this->preferences->expects($this->never())->method('get');
		$this->streams->expects($this->never())->method('recommendationCandidatesByTerms');
		$this->queue->expects($this->never())->method('fillPosts');

		$result = $this->service()->discover($this->viewer(), 'tech.apple');

		$this->assertTrue($result['underfilled']);
		$this->assertFalse($result['remote_refresh_queued']);
		$this->assertSame([], $result['candidates']);
	}

	public function testSelectedTopicReturnsLocalMatchAndQueuesOnlyBoundedBackgroundRefresh(): void {
		$this->preferences->method('isEnabled')->willReturn(true);
		$this->preferences->method('get')->with('alice')->willReturn([
			'revision' => 0,
			'generation' => 0,
			'learning_mode' => 'on',
			'effective_topics' => ['tech.apple' => 'INTERESTED'],
		]);
		$this->interests->method('languagesFor')->with('alice')->willReturn(['en']);
		$this->interests->method('hiddenFor')->with($this->isInstanceOf(Person::class))->willReturn([]);
		$note = new Note();
		$note->setId('https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz/post/3k')->setContent('<p>Apple announces a new device</p>')->setLanguage('en')->setPublishedTime(1_999_999_900);
		$this->streams->expects($this->once())->method('setViewer')->with($this->isInstanceOf(Person::class));
		$this->streams->expects($this->once())->method('recommendationCandidatesByTerms')->with(
			$this->appleAliases(), $this->isType('string'), 100, [], ['en'], 2_000_000_000
		)->willReturn(['123' => $note]);
		$this->queue->expects($this->exactly(2))->method('fillPosts')->willReturnCallback(
			static function (string $kind, string $term, Person $viewer): bool {
				self::assertContains($kind, [PostDiscoveryService::TAG, PostDiscoveryService::SEARCH]);
				self::assertSame('apple', $term);
				self::assertSame('alice', $viewer->getUserId());
				return true;
			}
		);

		$result = $this->service()->discover($this->viewer(), 'tech.apple');

		$this->assertSame(1, $result['count']);
		$this->assertTrue($result['underfilled']);
		$this->assertTrue($result['remote_refresh_queued']);
		$this->assertSame('text', $result['candidates'][0]['format']);
		$this->assertSame('atproto', $result['candidates'][0]['protocol']);
		$this->assertSame(['activitypub' => 0, 'atproto' => 1], $result['sources']);
		$this->assertSame(RecommendationRanker::ALGORITHM_VERSION, $result['candidates'][0]['algorithm_version']);
		$this->assertArrayHasKey('rank_score', $result['candidates'][0]);
		$this->assertSame(1.0, $result['candidates'][0]['rank_features']['novelty']);
	}

	public function testRemoteDiscoveryPauseKeepsCachedCandidatesAvailableWithoutQueueing(): void {
		$this->preferences->method('isEnabled')->willReturn(true);
		$this->preferences->method('remoteDiscoveryPaused')->willReturn(true);
		$this->preferences->method('get')->willReturn([
			'revision' => 0, 'generation' => 0, 'learning_mode' => 'on',
			'effective_topics' => ['tech.apple' => 'INTERESTED'],
		]);
		$this->interests->method('languagesFor')->willReturn(['en']);
		$this->interests->method('hiddenFor')->willReturn([]);
		$note = new Note();
		$note->setId('https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz/post/3k')->setContent('<p>Apple announces a new device</p>')->setLanguage('en')->setPublishedTime(1_999_999_900);
		$this->streams->method('recommendationCandidatesByTerms')->willReturn(['123' => $note]);
		$this->queue->expects($this->never())->method('fillPosts');

		$result = $this->service()->discover($this->viewer(), 'tech.apple');

		$this->assertSame(1, $result['count']);
		$this->assertTrue($result['underfilled']);
		$this->assertFalse($result['remote_refresh_queued']);
	}

	public function testLessNeutralAndHiddenPreferencesDoNotReadOrQueueCandidates(): void {
		$this->preferences->method('isEnabled')->willReturn(true);
		$this->preferences->method('get')->willReturnOnConsecutiveCalls(
			['effective_topics' => ['tech.apple' => 'LESS']],
			['effective_topics' => ['tech.apple' => 'NEUTRAL']],
			['effective_topics' => ['tech.apple' => 'HIDE']]
		);
		$this->streams->expects($this->never())->method('recommendationCandidatesByTerms');
		$this->queue->expects($this->never())->method('fillPosts');

		$this->service()->discover($this->viewer(), 'tech.apple');
		$this->service()->discover($this->viewer(), 'tech.apple');
		$this->service()->discover($this->viewer(), 'tech.apple');
	}

	public function testCursorPageRechecksVisibilityAndRefillsFromTheSameSnapshot(): void {
		$this->preferences->method('isEnabled')->willReturn(true);
		$this->preferences->method('get')->willReturn([
			'revision' => 3,
			'generation' => 2,
			'learning_mode' => 'on',
			'effective_topics' => ['tech.apple' => 'INTERESTED'],
		]);
		$first = (new Note())->setId('https://remote.example/posts/2')->setContent('<p>Apple publishes an iPhone update</p>')->setPublishedTime(1_999_999_900);
		$second = (new Note())->setId('https://remote.example/posts/3')->setContent('<p>Apple publishes another iPhone update</p>')->setPublishedTime(1_999_999_800);
		$this->streams->expects($this->once())->method('setViewer')->with($this->isInstanceOf(Person::class));
		$this->streams->expects($this->exactly(2))->method('getVisibleByNids')->willReturnOnConsecutiveCalls(
			['2' => $first],
			['3' => $second]
		);
		$this->snapshots->expects($this->exactly(2))->method('nextPage')->willReturnOnConsecutiveCalls(
			['session_id' => 'session', 'nids' => ['1', '2'], 'next_cursor' => 'cursor-two'],
			['session_id' => 'session', 'nids' => ['3', '4'], 'next_cursor' => null],
		);
		$this->queue->expects($this->never())->method('fillPosts');

		$result = $this->service()->discover($this->viewer(), 'tech.apple', 2, 'cursor-one');

		$this->assertSame(['2', '3'], array_column($result['candidates'], 'nid'));
		$this->assertSame('session', $result['session_id']);
		$this->assertNull($result['next_cursor']);
		$this->assertFalse($result['cursor_expired']);
	}

	public function testExplicitFeedbackChangesNewRankingAndHidesPostsBeforeTheCandidateQuery(): void {
		$this->preferences->method('isEnabled')->willReturn(true);
		$this->preferences->method('get')->willReturn([
			'revision' => 0,
			'generation' => 0,
			'learning_mode' => 'on',
			'effective_topics' => ['tech.apple' => 'INTERESTED'],
		]);
		$feedback = ['hidden_nids' => ['321'], 'topics' => ['tech.apple' => 'MORE_TOPIC'], 'authors' => [], 'formats' => []];
		$this->feedback = $this->createMock(RecommendationFeedbackService::class);
		$this->feedback->method('current')->with('alice')->willReturn($feedback);
		$this->interests->method('languagesFor')->willReturn([]);
		$this->interests->method('hiddenFor')->willReturn([]);
		$note = new Note();
		$note->setId('https://remote.example/posts/123')->setContent('<p>Apple announces a new device</p>')->setPublishedTime(1_999_999_900);
		$this->streams->expects($this->once())->method('recommendationCandidatesByTerms')->with(
			$this->appleAliases(), $this->isType('string'), 100, ['321'], [], 2_000_000_000
		)->willReturn(['123' => $note]);
		$this->feedback->expects($this->once())->method('apply')->with(
			$this->callback(static fn (array $candidate): bool => $candidate['nid'] === '123'), 'tech.apple', $feedback
		)->willReturnCallback(static function (array $candidate): array {
			$candidate['topic_match'] = min(1.0, $candidate['topic_match'] + 0.28);
			return $candidate;
		});
		$this->queue->expects($this->exactly(2))->method('fillPosts')->willReturn(false);

		$result = $this->service()->discover($this->viewer(), 'tech.apple');

		$this->assertSame('123', $result['candidates'][0]['nid']);
		$this->assertSame(1.0, $result['candidates'][0]['rank_features']['topic_match']);
	}
}
