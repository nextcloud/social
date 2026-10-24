<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Reader\BlueskyFeeds;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationSavedFeedCandidates;
use OCA\Social\Service\RecommendationTopicCatalog;
use PHPUnit\Framework\TestCase;

class RecommendationSavedFeedCandidatesTest extends TestCase {
	public function testSavedFeedCandidatesComeFromCachedVisiblePostsAndRespectLanguageFilters(): void {
		$viewer = (new Person())->setId('https://social.test/@alice')->setUserId('alice');
		$apple = (new Note())->setId('at://did:plc:bob/app.bsky.feed.post/1')->setNid(101)->setContent('<p>Apple releases an iPhone update</p>')->setLanguage('en');
		$wrongLanguage = (new Note())->setId('at://did:plc:bob/app.bsky.feed.post/2')->setNid(102)->setContent('<p>Apple releases an iPhone update</p>')->setLanguage('fr');
		$hiddenTopic = (new Note())->setId('at://did:plc:bob/app.bsky.feed.post/3')->setNid(103)->setContent('<p>Apple releases an iPhone photography update</p>')->setLanguage('en');
		$feeds = $this->createMock(BlueskyFeeds::class);
		$feeds->expects($this->once())->method('recommendationFeedPostNids')->with($viewer, 15)->willReturn(['101', '102', '103']);
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->once())->method('setViewer')->with($viewer);
		$streams->expects($this->once())->method('getVisibleByNids')->with(['101', '102', '103'])->willReturn([
			'101' => $apple,
			'102' => $wrongLanguage,
			'103' => $hiddenTopic,
		]);
		$source = new RecommendationSavedFeedCandidates($feeds, $streams, new RecommendationFeatureExtractor(new RecommendationTopicCatalog()));

		$result = $source->matching($viewer, 3, [], ['en'], ['tech.apple']);

		$this->assertSame([101, 103], array_keys($result));
	}

	public function testEmptyAutomaticProfileDoesNotReadSavedFeedCache(): void {
		$viewer = (new Person())->setId('https://social.test/@alice')->setUserId('alice');
		$feeds = $this->createMock(BlueskyFeeds::class);
		$feeds->expects($this->never())->method('recommendationFeedPostNids');
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->never())->method('getVisibleByNids');
		$source = new RecommendationSavedFeedCandidates($feeds, $streams, new RecommendationFeatureExtractor(new RecommendationTopicCatalog()));

		$this->assertSame([], $source->matching($viewer, 10, [], [], []));
	}
}
