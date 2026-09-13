<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\StatisticsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatisticsServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';

	private StreamRequest|MockObject $streamRequest;
	private FollowsRequest|MockObject $followsRequest;
	private StatisticsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->service = new StatisticsService(
			$this->streamRequest,
			$this->followsRequest,
			$this->createMock(AccountService::class),
		);
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setId(self::ALICE)
			->setPreferredUsername('alice');
		$actor->setCreation(strtotime('2024-03-17T09:00:00Z'));

		return $actor;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function post(int $nid, array $values = []): Note {
		$post = new Note();
		$post->setNid($nid);
		$post->setId(self::ALICE . '/' . $nid);
		$post->setContent($values['content'] ?? '<p>hello</p>');
		$post->setPublished($values['published'] ?? '2026-08-03T21:30:34Z');
		$post->setPublishedTime(strtotime($values['published'] ?? '2026-08-03T21:30:34Z'));
		$post->setVisibility($values['visibility'] ?? Stream::TYPE_PUBLIC);
		$post->setInReplyTo($values['in_reply_to'] ?? '');
		$post->setDetailInt('likes', $values['likes'] ?? 0);
		$post->setDetailInt('boosts', $values['boosts'] ?? 0);
		$post->setDetailInt('replies', $values['replies'] ?? 0);
		$post->setHashtags($values['hashtags'] ?? []);
		if ($values['media'] ?? false) {
			$post->setAttachments([new MediaAttachment()]);
		}

		return $post;
	}

	/**
	 * @param Stream[] $posts
	 */
	private function answering(array $posts): void {
		// answered a page at a time, the size the caller asked for, so the walk
		// is exercised rather than handed everything at once
		$remaining = $posts;
		$this->streamRequest->method('getTimeline')
			->willReturnCallback(static function (ProbeOptions $options) use (&$remaining): array {
				return array_splice($remaining, 0, max(1, $options->getLimit()));
			});
	}

	public function testItCountsWhatTheAccountWroteAndWhatCameBack(): void {
		$this->answering([
			$this->post(3, ['likes' => 9, 'boosts' => 4, 'replies' => 2, 'hashtags' => ['nextcloud']]),
			$this->post(2, ['likes' => 1, 'in_reply_to' => self::ALICE . '/1', 'hashtags' => ['nextcloud', 'a11y']]),
			$this->post(1, ['media' => true, 'visibility' => Stream::TYPE_FOLLOWERS]),
		]);
		$this->followsRequest->method('countFollowers')->willReturn(26);
		$this->followsRequest->method('countFollowing')->willReturn(24);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(3, $stats['posts']['total']);
		$this->assertSame(2, $stats['posts']['originals']);
		$this->assertSame(1, $stats['posts']['replies']);
		$this->assertSame(1, $stats['posts']['with_media']);
		$this->assertSame(10, $stats['engagement']['likes']);
		$this->assertSame(4, $stats['engagement']['boosts']);
		$this->assertSame(2, $stats['engagement']['replies']);
		// per post the account wrote, which is all three of these
		$this->assertSame(3.3, $stats['engagement']['likes_per_post']);
		$this->assertSame(2, $stats['visibility'][Stream::TYPE_PUBLIC]);
		$this->assertSame(1, $stats['visibility'][Stream::TYPE_FOLLOWERS]);
		$this->assertSame(26, $stats['account']['followers']);
		$this->assertSame(24, $stats['account']['following']);
	}

	/** A boost is something the account did, not something it wrote. */
	public function testABoostIsCountedButItsLikesAreNotTheAccountsOwn(): void {
		$boost = new Stream();
		$boost->setNid(2);
		$boost->setId(self::ALICE . '/2');
		$boost->setType('Announce');
		$boost->setPublishedTime(strtotime('2026-08-04T10:00:00Z'));
		$boost->setDetailInt('likes', 100);

		$this->answering([$boost, $this->post(1, ['likes' => 3])]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(2, $stats['posts']['total']);
		$this->assertSame(1, $stats['posts']['boosts']);
		$this->assertSame(1, $stats['posts']['originals']);
		// the hundred likes belong to whoever wrote the boosted post
		$this->assertSame(3, $stats['engagement']['likes']);
	}

	public function testTheBestPostsAreTheMostAnsweredOnesInOrder(): void {
		$this->answering([
			$this->post(3, ['likes' => 1, 'boosts' => 1, 'content' => '<p>quiet</p>']),
			$this->post(2, ['likes' => 9, 'boosts' => 4, 'content' => '<p>loud</p>']),
			$this->post(1, ['likes' => 5, 'boosts' => 0, 'content' => '<p>middling</p>']),
		]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(['loud', 'middling', 'quiet'], array_column($stats['best'], 'excerpt'));
		$this->assertSame('2', $stats['best'][0]['id']);
	}

	/** The excerpt is a post's text, not its markup. */
	public function testAnExcerptCarriesNoMarkupAndIsCutShort(): void {
		$this->answering([
			$this->post(1, ['content' => '<p>a <a href="https://example.org">link</a> &amp; some <b>bold</b></p>']),
			$this->post(2, ['content' => '<p>' . str_repeat('long ', 40) . '</p>']),
		]);

		$stats = $this->service->forAccount($this->alice());
		$excerpts = array_column($stats['best'], 'excerpt');

		$this->assertContains('a link & some bold', $excerpts);
		foreach ($excerpts as $excerpt) {
			$this->assertStringNotContainsString('<', $excerpt);
			$this->assertLessThanOrEqual(90, mb_strlen($excerpt));
		}
	}

	/** Twelve columns whether or not anything was posted in them. */
	public function testTheMonthsAreAlwaysTwelveAndAlwaysInOrder(): void {
		$this->answering([$this->post(1, ['published' => gmdate('Y-m-01\TH:i:s\Z')])]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertCount(12, $stats['by_month']);
		$this->assertSame(array_keys($stats['by_month']), array_keys($stats['by_month']));
		$this->assertSame(1, $stats['by_month'][gmdate('Y-m')]);
		$this->assertCount(24, $stats['by_hour']);
	}

	public function testTheHashtagsAreTheMostUsedOnesLowercased(): void {
		$this->answering([
			$this->post(1, ['hashtags' => ['NextCloud', 'a11y']]),
			$this->post(2, ['hashtags' => ['nextcloud']]),
		]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame('nextcloud', $stats['hashtags'][0]['name']);
		$this->assertSame(2, $stats['hashtags'][0]['count']);
	}

	/** The walk stops, and says that it did. */
	public function testTheWalkIsBoundedAndTheAnswerSaysSo(): void {
		// newest first, which is the order a timeline answers in and the order
		// the keyset walk depends on
		$posts = [];
		for ($nid = StatisticsService::MAX_POSTS + 100; $nid >= 1; $nid--) {
			$posts[] = $this->post($nid);
		}
		$this->answering($posts);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(StatisticsService::MAX_POSTS, $stats['window']['counted']);
		$this->assertTrue($stats['window']['capped']);
	}

	/** An account that has posted nothing is a page of zeroes, not a crash. */
	public function testAnAccountWithNoPostsDividesByNothing(): void {
		$this->answering([]);

		$stats = $this->service->forAccount($this->alice());

		$this->assertSame(0, $stats['posts']['total']);
		$this->assertSame(0.0, $stats['engagement']['likes_per_post']);
		$this->assertSame([], $stats['best']);
		$this->assertSame([], $stats['hashtags']);
		$this->assertFalse($stats['window']['capped']);
	}

	/** The walk asks for the account's own posts and nothing else. */
	public function testItAsksForTheAccountsOwnTimeline(): void {
		$seen = null;
		$this->streamRequest->method('getTimeline')
			->willReturnCallback(static function (ProbeOptions $options) use (&$seen): array {
				$seen ??= $options;

				return [];
			});

		$this->service->forAccount($this->alice());

		$this->assertSame(ProbeOptions::ACCOUNT, $seen?->getProbe());
		$this->assertSame(self::ALICE, $seen?->getAccountId());
	}
}
