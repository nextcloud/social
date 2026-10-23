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
use OCA\Social\Service\InterestService;
use OCA\Social\Service\RecommendationCandidateService;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationPreferenceService;
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

	protected function setUp(): void {
		$this->preferences = $this->createMock(RecommendationPreferenceService::class);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->queue = $this->createMock(RemoteFetchQueue::class);
		$this->interests = $this->createMock(InterestService::class);
		$this->clock = $this->createStub(ITimeFactory::class);
		$this->clock->method('getTime')->willReturn(2_000_000_000);
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
			'effective_topics' => ['tech.apple' => 'INTERESTED'],
		]);
		$this->interests->method('languagesFor')->with('alice')->willReturn(['en']);
		$this->interests->method('hiddenFor')->with($this->isInstanceOf(Person::class))->willReturn([]);
		$note = new Note();
		$note->setId('https://remote.example/posts/1')->setContent('<p>Apple announces a new device</p>')->setLanguage('en')->setPublishedTime(1_999_999_900);
		$this->streams->expects($this->once())->method('setViewer')->with($this->isInstanceOf(Person::class));
		$this->streams->expects($this->once())->method('recommendationCandidatesByTerms')->with(
			['apple', 'iphone', 'ipad', 'mac'], $this->isType('string'), 100, [], ['en']
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
		$this->assertSame('activitypub', $result['candidates'][0]['protocol']);
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
}
