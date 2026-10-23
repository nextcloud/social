<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\RecommendationSignalRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationPreferenceService;
use OCA\Social\Service\RecommendationSignalService;
use OCA\Social\Service\RecommendationTopicCatalog;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class RecommendationSignalServiceTest extends TestCase {
	private RecommendationPreferenceService $preferences;
	private RecommendationSignalRequest $signals;
	private StreamRequest $streams;
	private RecommendationSignalService $service;
	private int $generation = 7;
	private string $mode = 'on';

	protected function setUp(): void {
		$this->preferences = $this->createMock(RecommendationPreferenceService::class);
		$this->preferences->method('isEnabled')->willReturn(true);
		$this->preferences->method('get')->willReturnCallback(fn (): array => [
			'revision' => 8, 'generation' => $this->generation, 'learning_mode' => $this->mode,
			'learning' => $this->mode !== 'off', 'topics' => [],
		]);
		$this->signals = $this->createMock(RecommendationSignalRequest::class);
		$this->streams = $this->createMock(StreamRequest::class);
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn(2_000_000_000);
		$catalog = new RecommendationTopicCatalog();
		$this->service = new RecommendationSignalService(
			$this->preferences,
			$this->signals,
			new RecommendationFeatureExtractor($catalog),
			$this->streams,
			$clock,
		);
	}

	private function viewer(): Person {
		return (new Person())->setId('https://local.test/users/alice')->setUserId('alice');
	}

	private function parentPost(): Note {
		return (new Note())
			->setNid('123')
			->setId('https://remote.test/posts/123')
			->setAttributedTo('https://remote.test/users/bob')
			->setContent('<p>Apple releases a new laptop</p>')
			->setHashtags(['apple']);
	}

	public function testReplyIsRecordedAsEngagementWithoutInferringTopicOrAuthorAffinity(): void {
		$post = $this->parentPost();
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $post]);
		$this->signals->expects($this->once())->method('record')->with(
			'alice', $this->callback(static fn (string $id): bool => preg_match('/^[a-f0-9-]{36}$/i', $id) === 1), 7, 'reply',
			[], null, 2_000_000_000
		)->willReturn(['accepted' => true, 'duplicate' => false, 'profile_version' => 9]);

		$this->service->recordAction($this->viewer(), $post, 'reply');
	}

	public function testSeparateActionTransitionsWithinOneSecondReceiveDistinctEventIds(): void {
		$post = $this->parentPost();
		$this->streams->method('getVisibleByNids')->willReturn(['123' => $post]);
		$ids = [];
		$deltas = [];
		$this->signals->expects($this->exactly(3))->method('record')->willReturnCallback(
			static function (string $actor, string $eventId, int $generation, string $kind, array $signals) use (&$ids, &$deltas): array {
				$ids[] = $eventId;
				$topics = array_values(array_filter($signals, static fn (array $signal): bool => $signal['dimension'] === 'topics'));
				$deltas[] = $topics[0]['delta'];
				return ['accepted' => true, 'duplicate' => false, 'profile_version' => count($ids) + 8];
			}
		);

		$this->service->recordAction($this->viewer(), $post, 'like');
		$this->service->recordAction($this->viewer(), $post, 'unlike');
		$this->service->recordAction($this->viewer(), $post, 'like');

		$this->assertCount(3, array_unique($ids));
		$this->assertSame([0.35, -0.35, 0.35], $deltas);
	}

	public function testOffPausedAndStaleGenerationEventsAreDiscardedBeforeReadingPosts(): void {
		$this->mode = 'off';
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->signals->expects($this->never())->method('record');
		$event = ['event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7, 'kind' => 'like', 'status_id' => '123'];
		$this->assertSame(1, $this->service->recordEvents($this->viewer(), [$event])['discarded']);

		$this->mode = 'paused';
		$this->assertSame(1, $this->service->recordEvents($this->viewer(), [$event])['discarded']);

		$this->mode = 'on';
		$this->generation++;
		$this->assertSame(1, $this->service->recordEvents($this->viewer(), [$event])['discarded']);
	}

	public function testInstanceWideLearningPauseBlocksBrowserAndNativeActionSignals(): void {
		$this->preferences->method('learningPaused')->willReturn(true);
		$this->streams->expects($this->never())->method('getVisibleByNids');
		$this->signals->expects($this->never())->method('record');
		$event = ['event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7, 'kind' => 'like', 'status_id' => '123'];

		$this->assertSame(1, $this->service->recordEvents($this->viewer(), [$event])['discarded']);
		$this->service->recordAction($this->viewer(), $this->parentPost(), 'like');
	}

	public function testImpressionRequiresAQualifiedVisiblePostAndSessionId(): void {
		$this->streams->expects($this->once())->method('getVisibleByNids')->willReturn(['123' => $this->parentPost()]);
		$this->signals->expects($this->once())->method('record')->with(
			'alice', '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 7, 'impression', [],
			['post_key' => hash('sha256', '123'), 'session_key' => 'f7f454f1-d33d-4fb5-a132-335837049d23'], 2_000_000_000
		)->willReturn(['accepted' => true, 'duplicate' => false, 'profile_version' => 9]);

		$result = $this->service->recordEvents($this->viewer(), [[
			'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
			'kind' => 'impression', 'context' => 'interests', 'status_id' => '123',
			'session_id' => 'f7f454f1-d33d-4fb5-a132-335837049d23', 'visible_ms' => 750,
		]]);

		$this->assertSame(1, $result['accepted']);
	}

	public function testVisiblePostDetailEventsCanTeachWhenConsentIsOn(): void {
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $this->parentPost()]);
		$this->signals->expects($this->once())->method('record')->willReturn(['accepted' => true, 'duplicate' => false, 'profile_version' => 9]);

		$result = $this->service->recordEvents($this->viewer(), [[
			'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
			'kind' => 'open', 'context' => 'detail', 'status_id' => '123',
		]]);

		$this->assertSame(1, $result['accepted']);
	}

	public function testVideoWatchRatioUsesActualPlaybackFractionAndOnlyForVideoPosts(): void {
		$video = $this->parentPost()->setAttachments([(new MediaAttachment())->setType('video')]);
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $video]);
		$projected = [];
		$this->signals->expects($this->once())->method('record')->willReturnCallback(
			static function (string $actor, string $eventId, int $generation, string $kind, array $signals) use (&$projected): array {
				$projected = $signals;
				return ['accepted' => true, 'duplicate' => false, 'profile_version' => 9];
			}
		);

		$result = $this->service->recordEvents($this->viewer(), [[
			'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
			'kind' => 'video_watch_ratio', 'context' => 'shorts', 'status_id' => '123', 'ratio' => 0.75,
		]]);

		$this->assertSame(1, $result['accepted']);
		$this->assertSame([0.15, 0.15], array_column(array_values(array_filter(
			$projected, static fn (array $signal): bool => $signal['dimension'] === 'topics'
		)), 'delta'));
	}

	public function testVideoWatchRatioRejectsInvalidFractionsAndNonVideoPosts(): void {
		$this->streams->expects($this->once())->method('getVisibleByNids')->with(['123'])->willReturn(['123' => $this->parentPost()]);
		$this->signals->expects($this->never())->method('record');
		$result = $this->service->recordEvents($this->viewer(), [
			[
				'event_id' => '1d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
				'kind' => 'video_watch_ratio', 'context' => 'shorts', 'status_id' => '123', 'ratio' => 1.1,
			],
			[
				'event_id' => '2d9cfd49-4e69-4bb8-b7fc-854df448d310', 'generation' => 7,
				'kind' => 'video_watch_ratio', 'context' => 'shorts', 'status_id' => '123', 'ratio' => 0.95,
			],
		]);

		$this->assertSame(2, $result['discarded']);
	}

	public function testConsentGatedExposurePenaltyUsesHashedPostKeyAndRecentImpressions(): void {
		$post = $this->parentPost();
		$this->signals->expects($this->once())->method('exposures')->with(
			'alice', 7, [hash('sha256', '123')], 1_997_408_000
		)->willReturn([
			hash('sha256', '123') => ['impressions' => 2, 'updated_at' => 2_000_000_000],
		]);

		$this->assertSame(['123' => 0.5], $this->service->exposurePenalties($this->viewer(), [$post]));
	}

	public function testLearningOffDoesNotReadStoredExposureHistory(): void {
		$this->mode = 'off';
		$this->signals->expects($this->never())->method('exposures');

		$this->assertSame([], $this->service->exposurePenalties($this->viewer(), [$this->parentPost()]));
	}
}
