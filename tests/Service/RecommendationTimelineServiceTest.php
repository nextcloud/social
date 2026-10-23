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
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\Discovery\PostDiscoveryService;
use OCA\Social\Service\InterestFeedService;
use OCA\Social\Service\InterestService;
use OCA\Social\Service\RecommendationBehavioralProfile;
use OCA\Social\Service\RecommendationContentCluster;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationFeedbackService;
use OCA\Social\Service\RecommendationFollowFeatures;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationRanker;
use OCA\Social\Service\RecommendationReRanker;
use OCA\Social\Service\RecommendationSignalService;
use OCA\Social\Service\RecommendationSnapshotStore;
use OCA\Social\Service\RecommendationTimelineService;
use OCA\Social\Service\RecommendationTopicCatalog;
use OCA\Social\Service\RemoteFetchQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class RecommendationTimelineServiceTest extends TestCase {
	private RecommendationPreferenceService $preferences;
	private StreamRequest $streams;
	private InterestService $interests;
	private InterestFeedService $legacyFeed;
	private RecommendationSnapshotStore $snapshots;
	private RecommendationFeedbackService $feedback;
	private RemoteFetchQueue $queue;
	private ITimeFactory $clock;
	private RecommendationTopicCatalog $catalog;
	private RecommendationSignalRequest $signals;
	private FollowsRequest $follows;
	private array $learnedProfile = ['topics' => [], 'authors' => [], 'formats' => []];
	private array $currentFeedback = ['hidden_nids' => [], 'similarities' => [], 'topics' => [], 'authors' => [], 'formats' => []];

	/** @return list<string> */
	private function appleAliases(): array {
		return $this->catalog->find('tech.apple')['aliases'] ?? [];
	}

	protected function setUp(): void {
		$this->preferences = $this->createMock(RecommendationPreferenceService::class);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->interests = $this->createMock(InterestService::class);
		$this->legacyFeed = $this->createMock(InterestFeedService::class);
		$this->snapshots = $this->createMock(RecommendationSnapshotStore::class);
		$this->feedback = $this->createMock(RecommendationFeedbackService::class);
		$this->feedback->method('current')->willReturnCallback(fn (): array => $this->currentFeedback);
		$this->queue = $this->createMock(RemoteFetchQueue::class);
		$this->clock = $this->createStub(ITimeFactory::class);
		$this->clock->method('getTime')->willReturn(2_000_000_000);
		$this->catalog = new RecommendationTopicCatalog();
		$this->signals = $this->createMock(RecommendationSignalRequest::class);
		$this->follows = $this->createMock(FollowsRequest::class);
		$this->follows->method('getBetweenMany')->willReturn(['following' => [], 'followedBy' => []]);
		$this->signals->method('profile')->willReturnCallback(fn (): array => $this->learnedProfile);
		$this->preferences->method('isEnabled')->willReturn(true);
	}

	private function service(): RecommendationTimelineService {
		return new RecommendationTimelineService(
			$this->preferences,
			$this->catalog,
			new RecommendationFeatureExtractor($this->catalog),
			$this->streams,
			$this->interests,
			$this->legacyFeed,
			new RecommendationRanker(),
			new RecommendationReRanker(),
			$this->snapshots,
			$this->feedback,
			new RecommendationBehavioralProfile(new RecommendationSignalService(
				$this->preferences,
				$this->signals,
				new RecommendationFeatureExtractor($this->catalog),
				$this->streams,
				$this->clock,
			), $this->catalog),
			new RecommendationFollowFeatures($this->follows),
			$this->queue,
			$this->clock,
		);
	}

	public function testAcceptedFollowSignalAffectsRankingIndependentlyOfAuthorAffinity(): void {
		$this->preferences->method('get')->willReturn($this->state([['topic_id' => 'tech.apple', 'mode' => 'INTERESTED']]));
		$this->interests->method('hiddenFor')->willReturn([]);
		$this->interests->method('languagesFor')->willReturn([]);
		$followedAuthor = 'https://remote.example/users/followed';
		$followed = (new Note())
			->setId('https://remote.example/posts/followed')
			->setAttributedTo($followedAuthor)
			->setContent('<p>Apple releases a new device</p>')
			->setPublishedTime(1_999_999_900);
		$unfollowed = (new Note())
			->setId('https://remote.example/posts/unfollowed')
			->setAttributedTo('https://remote.example/users/unfollowed')
			->setContent('<p>Apple releases a new device</p>')
			->setPublishedTime(1_999_999_900);
		$this->streams->method('recommendationCandidatesByTerms')->willReturn([
			'123' => $unfollowed,
			'124' => $followed,
		]);
		$acceptedFollow = (new Follow())->setAccepted(true);
		$this->follows->expects($this->once())->method('getBetweenMany')
			->with('https://example.test/users/alice', [$unfollowed->getAttributedTo(), $followedAuthor])
			->willReturn(['following' => [$followedAuthor => $acceptedFollow], 'followedBy' => []]);
		$this->snapshots->method('create')->willReturn(null);

		$page = $this->service()->page($this->viewer(), 2);

		$this->assertSame(['https://remote.example/posts/followed', 'https://remote.example/posts/unfollowed'], array_map(
			static fn (Note $post): string => $post->getId(),
			$page['items']
		));
	}

	public function testSimilarityFeedbackChangesTheNextSessionWithoutUsingAuthorAffinity(): void {
		$this->preferences->method('get')->willReturn($this->state([['topic_id' => 'tech.apple', 'mode' => 'INTERESTED']]));
		$this->interests->method('hiddenFor')->willReturn([]);
		$this->interests->method('languagesFor')->willReturn([]);
		$similar = (new Note())
			->setId('https://remote.example/posts/similar')
			->setAttributedTo('https://remote.example/users/a')
			->setContent('<p>Apple introduces a new iPhone</p>')
			->setPublishedTime(1_999_999_900);
		$other = (new Note())
			->setId('https://remote.example/posts/other')
			->setAttributedTo('https://remote.example/users/b')
			->setContent('<p>Apple introduces an iPhone app with programming and software development tools</p>')
			->setPublishedTime(1_999_999_900);
		$features = (new RecommendationFeatureExtractor($this->catalog))->extract($similar);
		$key = RecommendationContentCluster::key(array_keys($features['topics']), $features['format'], $this->catalog);
		$this->currentFeedback['similarities'][$key] = 'MORE_SIMILAR';
		$this->streams->method('recommendationCandidatesByTerms')->willReturn(['100' => $other, '101' => $similar]);
		$this->snapshots->method('create')->willReturn(null);

		$page = $this->service()->page($this->viewer(), 2);

		$this->assertSame('https://remote.example/posts/similar', $page['items'][0]->getId());
	}

	private function viewer(): Person {
		return (new Person())->setId('https://example.test/users/alice')->setUserId('alice')->setLocal(true);
	}

	private function state(array $topics = [], array $effective = ['tech.apple' => 'INTERESTED']): array {
		return ['revision' => 3, 'generation' => 2, 'learning_mode' => 'on', 'learning' => true, 'topics' => $topics, 'effective_topics' => $effective];
	}

	public function testExplicitTopicPreferencesServeACombinedRankedStatusPage(): void {
		$this->preferences->method('get')->with('alice')->willReturn($this->state([['topic_id' => 'tech.apple', 'mode' => 'INTERESTED']]));
		$this->interests->method('hiddenFor')->willReturn([]);
		$this->interests->method('languagesFor')->willReturn(['en']);
		$post = (new Note())
			->setId('https://remote.example/posts/apple')
			->setContent('<p>Apple announces a new device</p>')
			->setLanguage('en')
			->setPublishedTime(1_999_999_900);
		$this->streams->expects($this->once())->method('setViewer')->with($this->isInstanceOf(Person::class));
		$this->streams->expects($this->once())->method('recommendationCandidatesByTerms')->with(
			$this->appleAliases(), $this->isType('string'), 100, [], ['en']
		)->willReturn(['123' => $post]);
		$this->snapshots->expects($this->once())->method('create')->with(
			'alice', 'for-you-v4-candidate-recall-2', '', 3, 2, 2_000_000_000, ['123'], 20, []
		)->willReturn(['session_id' => 'session', 'nids' => ['123'], 'exploration_nids' => [], 'next_cursor' => null]);
		$this->queue->expects($this->exactly(2))->method('fillPosts')->willReturnCallback(
			static function (string $kind, string $term, Person $viewer): bool {
				self::assertContains($kind, [PostDiscoveryService::TAG, PostDiscoveryService::SEARCH]);
				self::assertSame('apple', $term);
				self::assertSame('alice', $viewer->getUserId());
				return true;
			}
		);

		$page = $this->service()->page($this->viewer(), 20);

		$this->assertSame('RANKED', $page['mode']);
		$this->assertSame('session', $page['session_id']);
		$this->assertTrue($page['coverage']['remote_refresh_queued']);
		$this->assertCount(1, $page['items']);
		$this->assertSame('topic', $page['items'][0]->getInterest()['reason']);
		$this->assertSame('tech.apple', $page['items'][0]->getInterest()['topics'][0]['id']);
	}

	public function testCandidateSearchCoversEverySelectedTopicBeforeAddingExtraAliases(): void {
		$selected = array_values(array_filter(
			$this->catalog->all(),
			static fn (array $topic): bool => !in_array('sensitive-topic', $topic['policyTags'], true)
		));
		$effective = [];
		$manual = [];
		foreach ($selected as $topic) {
			$effective[$topic['id']] = 'INTERESTED';
			$manual[] = ['topic_id' => $topic['id'], 'mode' => 'INTERESTED'];
		}
		$this->preferences->method('get')->willReturn($this->state($manual, $effective));
		$this->interests->method('hiddenFor')->willReturn([]);
		$this->interests->method('languagesFor')->willReturn([]);
		$this->streams->expects($this->once())->method('recommendationCandidatesByTerms')
			->willReturnCallback(static function (array $terms) use ($selected): array {
				self::assertLessThanOrEqual(20, count($terms));
				foreach ($selected as $topic) {
					self::assertContains($topic['aliases'][0], $terms, 'Every selected topic must contribute its primary search alias.');
				}
				return [];
			});
		$this->snapshots->method('create')->willReturn(null);
		$this->queue->method('fillPosts')->willReturn(false);

		$page = $this->service()->page($this->viewer(), 20);

		$this->assertSame([], $page['items']);
		$this->assertSame('UNDERFILLED', $page['coverage']['state']);
	}

	public function testUnderfilledRefreshWindowRotatesAcrossSelectedTopics(): void {
		$selected = array_values(array_filter(
			$this->catalog->all(),
			static fn (array $topic): bool => !in_array('sensitive-topic', $topic['policyTags'], true)
		));
		$effective = [];
		$manual = [];
		foreach ($selected as $topic) {
			$effective[$topic['id']] = 'INTERESTED';
			$manual[] = ['topic_id' => $topic['id'], 'mode' => 'INTERESTED'];
		}
		$this->preferences->method('get')->willReturn($this->state($manual, $effective));
		$this->interests->method('hiddenFor')->willReturn([]);
		$this->interests->method('languagesFor')->willReturn([]);
		$this->streams->method('recommendationCandidatesByTerms')->willReturn([]);
		$this->snapshots->method('create')->willReturn(null);

		$start = (intdiv(2_000_000_000, RemoteFetchQueue::POSTS_INTERVAL) * 2) % count($selected);
		$expectedCalls = [];
		for ($offset = 0; $offset < 2; $offset++) {
			$aliases = $selected[($start + $offset) % count($selected)]['aliases'];
			$expectedCalls[] = ['search', mb_strtolower($aliases[0], 'UTF-8')];
			foreach ($aliases as $alias) {
				$alias = mb_strtolower($alias, 'UTF-8');
				if (preg_match('/^[\p{L}\p{N}_-]{2,64}$/u', $alias) === 1) {
					$expectedCalls[] = ['tag', $alias];
					break;
				}
			}
		}
		$calls = [];
		$this->queue->expects($this->exactly(count($expectedCalls)))->method('fillPosts')
			->willReturnCallback(static function (string $kind, string $term, Person $viewer) use (&$calls): bool {
				$calls[] = [$kind, $term];
				return true;
			});

		$page = $this->service()->page($this->viewer(), 20);

		$this->assertSame($expectedCalls, $calls);
		$this->assertTrue($page['coverage']['remote_refresh_queued']);
	}

	public function testRemoteSearchUsesTheFullMultiwordTopicAlias(): void {
		$this->preferences->method('get')->willReturn($this->state(
			[['topic_id' => 'tech.ai', 'mode' => 'INTERESTED']],
			['tech.ai' => 'INTERESTED']
		));
		$this->interests->method('hiddenFor')->willReturn([]);
		$this->interests->method('languagesFor')->willReturn([]);
		$this->streams->method('recommendationCandidatesByTerms')->willReturn([]);
		$this->snapshots->method('create')->willReturn(null);
		$this->queue->expects($this->once())->method('fillPosts')
			->with(PostDiscoveryService::SEARCH, 'artificial intelligence', $this->isInstanceOf(Person::class))
			->willReturn(true);

		$page = $this->service()->page($this->viewer(), 20);

		$this->assertTrue($page['coverage']['remote_refresh_queued']);
	}

	public function testNoV4ChoicesPreserveLegacyHashtagFeedAsColdStart(): void {
		$this->preferences->method('get')->willReturn($this->state([], ['tech.apple' => null]));
		$this->interests->method('isEnabled')->willReturn(true);
		$post = new Note();
		$post->setId('https://remote.example/posts/legacy')->setNid('123');
		$this->legacyFeed->expects($this->once())->method('page')->with($this->isInstanceOf(Person::class), 1, '0', 0, '')->willReturn([$post]);

		$page = $this->service()->page($this->viewer(), 1);

		$this->assertSame('LEGACY', $page['mode']);
		$this->assertSame('legacy.3.2.MTIz', $page['next_cursor']);
		$this->assertSame($post, $page['items'][0]);
	}

	public function testAnAllHiddenProfileDoesNotFallBackAroundExplicitChoices(): void {
		$effective = ['tech.apple' => 'HIDE', 'technology' => 'HIDE'];
		$this->preferences->method('get')->willReturn($this->state([['topic_id' => 'technology', 'mode' => 'HIDE']], $effective));
		$this->interests->method('isEnabled')->willReturn(true);
		$this->legacyFeed->expects($this->never())->method('page');
		$this->streams->expects($this->never())->method('recommendationCandidatesByTerms');

		$page = $this->service()->page($this->viewer(), 20);

		$this->assertSame('NO_ACTIVE_TOPICS', $page['mode']);
		$this->assertSame([], $page['items']);
	}

	public function testLegacyCursorIsBoundToPreferenceRevisionAndGeneration(): void {
		$this->preferences->method('get')->willReturn($this->state([], ['tech.apple' => null]));
		$this->interests->method('isEnabled')->willReturn(true);
		$this->legacyFeed->expects($this->never())->method('page');

		$page = $this->service()->page($this->viewer(), 20, 'legacy.2.2.MTIz');

		$this->assertTrue($page['cursor_expired']);
		$this->assertSame([], $page['items']);
	}

	public function testLearnedInterestChangesTheNextRankedSession(): void {
		$this->preferences->method('get')->willReturn($this->state(
			[['topic_id' => 'technology', 'mode' => 'INTERESTED']],
			['technology' => 'INTERESTED']
		));
		$this->learnedProfile = [
			'topics' => ['tech.apple' => ['fast' => 3.0, 'slow' => 3.0, 'updated_at' => 2_000_000_000]],
			'authors' => [hash('sha256', 'https://remote.example/users/apple') => ['fast' => 3.0, 'slow' => 3.0, 'updated_at' => 2_000_000_000]],
			'formats' => [],
		];
		$signals = new RecommendationSignalService($this->preferences, $this->signals, new RecommendationFeatureExtractor($this->catalog), $this->streams, $this->clock);
		self::assertSame(1.0, $signals->profile('alice')['topics']['tech.apple']);
		$this->interests->method('hiddenFor')->willReturn([]);
		$this->interests->method('languagesFor')->willReturn([]);

		$apple = (new Note())
			->setId('https://remote.example/posts/apple')
			->setAttributedTo('https://remote.example/users/apple')
			->setContent('<p>Apple releases a device</p>')
			->setPublishedTime(1_999_999_900);
		$general = (new Note())
			->setId('https://remote.example/posts/technology')
			->setContent('<p>Technology changes how people work</p>')
			->setPublishedTime(1_999_999_900);
		$this->streams->method('recommendationCandidatesByTerms')->willReturn([
			'200' => $apple,
			'100' => $general,
		]);

		$page = $this->service()->page($this->viewer(), 2);

		$this->assertSame('https://remote.example/posts/apple', $page['items'][0]->getId());
		$this->assertSame('https://remote.example/posts/technology', $page['items'][1]->getId());
	}

	public function testExplorationSlotIsPersistedAndShownWithItsActualTopicReason(): void {
		$this->preferences->method('get')->willReturn($this->state([['topic_id' => 'tech.apple', 'mode' => 'INTERESTED']]));
		$this->interests->method('hiddenFor')->willReturn([]);
		$this->interests->method('languagesFor')->willReturn([]);
		$this->signals->method('exposures')->willReturn([]);
		$posts = [];
		for ($i = 1; $i <= 16; $i++) {
			$nid = (string)(100 + $i);
			$posts[$nid] = (new Note())
				->setId(sprintf('https://remote.example/posts/%02d', $i))
				->setNid($nid)
				->setAttributedTo('https://remote.example/users/' . $i)
				->setContent('<p>Apple releases a new device</p>')
				->setPublishedTime(1_999_999_900);
		}
		$this->streams->method('recommendationCandidatesByTerms')->willReturn($posts);
		$this->snapshots->method('create')->willReturnCallback(static function (...$arguments): array {
			return [
				'session_id' => 'exploration-session',
				'nids' => $arguments[6],
				'exploration_nids' => $arguments[8],
				'next_cursor' => null,
			];
		});

		$page = $this->service()->page($this->viewer(), 20);

		$exploration = array_values(array_filter($page['items'], static fn (Note $post): bool => $post->getInterest()['reason'] === 'exploration'));
		$this->assertCount(1, $exploration);
		$this->assertSame('tech.apple', $exploration[0]->getInterest()['topics'][0]['id']);
		$this->assertSame('exploration-session', $page['session_id']);
	}

	public function testExplorationExplanationSurvivesTheNextSnapshotPage(): void {
		$this->preferences->method('get')->willReturn($this->state([['topic_id' => 'tech.apple', 'mode' => 'INTERESTED']]));
		$this->signals->method('exposures')->willReturn([]);
		$post = (new Note())
			->setId('https://remote.example/posts/exploration')
			->setNid('123')
			->setAttributedTo('https://remote.example/users/new-voice')
			->setContent('<p>Apple announces a new device</p>')
			->setPublishedTime(1_999_999_900);
		$this->snapshots->expects($this->once())->method('nextPage')->willReturn([
			'session_id' => 'exploration-session',
			'nids' => ['123'],
			'exploration_nids' => ['123'],
			'next_cursor' => null,
		]);
		$this->streams->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $post]);

		$page = $this->service()->page($this->viewer(), 20, 'signed-cursor');

		$this->assertSame('exploration', $page['items'][0]->getInterest()['reason']);
		$this->assertSame('tech.apple', $page['items'][0]->getInterest()['topics'][0]['id']);
	}
}
