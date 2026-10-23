<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\Discovery\PostDiscoveryService;
use OCA\Social\Service\InterestFeedService;
use OCA\Social\Service\InterestService;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationFeedbackService;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationRanker;
use OCA\Social\Service\RecommendationReRanker;
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

	protected function setUp(): void {
		$this->preferences = $this->createMock(RecommendationPreferenceService::class);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->interests = $this->createMock(InterestService::class);
		$this->legacyFeed = $this->createMock(InterestFeedService::class);
		$this->snapshots = $this->createMock(RecommendationSnapshotStore::class);
		$this->feedback = $this->createMock(RecommendationFeedbackService::class);
		$this->feedback->method('current')->willReturn(['hidden_nids' => [], 'topics' => [], 'authors' => [], 'formats' => []]);
		$this->queue = $this->createMock(RemoteFetchQueue::class);
		$this->clock = $this->createStub(ITimeFactory::class);
		$this->clock->method('getTime')->willReturn(2_000_000_000);
		$this->catalog = new RecommendationTopicCatalog();
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
			$this->queue,
			$this->clock,
		);
	}

	private function viewer(): Person {
		return (new Person())->setId('https://example.test/users/alice')->setUserId('alice')->setLocal(true);
	}

	private function state(array $topics = [], array $effective = ['tech.apple' => 'INTERESTED']): array {
		return ['revision' => 3, 'generation' => 2, 'topics' => $topics, 'effective_topics' => $effective];
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
			['apple', 'iphone', 'ipad', 'mac'], $this->isType('string'), 100, [], ['en']
		)->willReturn(['123' => $post]);
		$this->snapshots->expects($this->once())->method('create')->with(
			'alice', 'for-you-v4', '', 3, 2, 2_000_000_000, ['123'], 20
		)->willReturn(['session_id' => 'session', 'nids' => ['123'], 'next_cursor' => null]);
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
}
