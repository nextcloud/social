<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\HashtagDoesNotExistException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\MiscService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HashtagServiceTest extends TestCase {
	private HashtagsRequest|MockObject $hashtagsRequest;
	private StreamRequest|MockObject $streamRequest;
	private HashtagService $service;

	protected function setUp(): void {
		$this->hashtagsRequest = $this->createMock(HashtagsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->service = new HashtagService(
			$this->hashtagsRequest,
			$this->streamRequest,
			$this->createMock(ConfigService::class),
			$this->createMock(MiscService::class),
		);
	}

	private function note(array $hashtags, int $publishedTime): Note {
		$note = new Note();
		$note->setHashtags($hashtags);
		$note->setPublishedTime($publishedTime);

		return $note;
	}

	public function testManageHashtagsComputesTrendPerWindowAndUpsertsEachTag(): void {
		$now = time();
		$notes = [
			$this->note(['nextcloud', 'social'], $now - 1800),
			$this->note(['nextcloud'], $now - 2 * 86400),
			$this->note(['php'], $now - 5 * 86400),
		];
		$requestedSince = [];
		$this->streamRequest->method('getNoteSince')
			->willReturnCallback(function (int $since) use ($notes, &$requestedSince) {
				$requestedSince[] = $since;

				return array_values(array_filter($notes, fn (Note $n) => $n->getPublishedTime() >= $since));
			});
		$this->hashtagsRequest->method('getAll')->willReturn([['hashtag' => 'nextcloud', 'trend' => []]]);

		$updated = [];
		$this->hashtagsRequest->expects($this->once())
			->method('update')
			->willReturnCallback(function (string $hashtag, array $trend) use (&$updated) {
				$updated[$hashtag] = $trend;
			});
		$saved = [];
		$this->hashtagsRequest->expects($this->exactly(2))
			->method('save')
			->willReturnCallback(function (string $hashtag, array $trend) use (&$saved) {
				$saved[$hashtag] = $trend;
			});

		$count = $this->service->manageHashtags();

		$this->assertSame(3, $count);
		$this->assertCount(5, $requestedSince);
		$windows = [HashtagService::TREND_1H, HashtagService::TREND_12H, HashtagService::TREND_1D, HashtagService::TREND_3D, HashtagService::TREND_10D];
		foreach ($windows as $i => $window) {
			$this->assertEqualsWithDelta($now - $window, $requestedSince[$i], 2);
		}
		$this->assertSame(['1h' => 1, '12h' => 1, '1d' => 1, '3d' => 2, '10d' => 2], $updated['nextcloud']);
		$this->assertSame(['1h' => 1, '12h' => 1, '1d' => 1, '3d' => 1, '10d' => 1], $saved['social']);
		$this->assertSame(['1h' => 0, '12h' => 0, '1d' => 0, '3d' => 0, '10d' => 1], $saved['php']);
	}

	public function testManageHashtagsWithoutRecentNotesTouchesNothing(): void {
		$this->streamRequest->method('getNoteSince')->willReturn([]);
		$this->hashtagsRequest->method('getAll')->willReturn([['hashtag' => 'old']]);
		$this->hashtagsRequest->expects($this->never())->method('save');
		$this->hashtagsRequest->expects($this->never())->method('update');

		$this->assertSame(0, $this->service->manageHashtags());
	}

	public function testManageHashtagsAggregatesASharedHashtagAcrossNotes(): void {
		$now = time();
		// Two notes share #nextcloud, so every window's trend must count it twice.
		// The window bounding lives in the DB layer; at service level getNoteSince()
		// drives the aggregation of the hashtags carried by the returned notes.
		$notes = [
			$this->note(['nextcloud'], $now - 30),
			$this->note(['nextcloud'], $now - 45),
		];
		$requestedSince = [];
		$this->streamRequest->method('getNoteSince')
			->willReturnCallback(function (int $since) use ($notes, &$requestedSince): array {
				$requestedSince[] = $since;

				return $notes;
			});
		$this->hashtagsRequest->method('getAll')->willReturn([]);

		$saved = [];
		$this->hashtagsRequest->expects($this->once())
			->method('save')
			->willReturnCallback(function (string $hashtag, array $trend) use (&$saved): void {
				$saved[$hashtag] = $trend;
			});
		$this->hashtagsRequest->expects($this->never())->method('update');

		$count = $this->service->manageHashtags();

		$this->assertSame(1, $count);
		$this->assertCount(5, $requestedSince);
		$windows = [HashtagService::TREND_1H, HashtagService::TREND_12H, HashtagService::TREND_1D, HashtagService::TREND_3D, HashtagService::TREND_10D];
		foreach ($windows as $i => $window) {
			$this->assertEqualsWithDelta($now - $window, $requestedSince[$i], 2);
		}
		$this->assertSame(['1h' => 2, '12h' => 2, '1d' => 2, '3d' => 2, '10d' => 2], $saved['nextcloud']);
	}

	public function testGetHashtagPrependsTheHashSign(): void {
		$this->hashtagsRequest->expects($this->exactly(2))
			->method('getHashtag')
			->with('#nextcloud')
			->willReturn(['hashtag' => '#nextcloud']);

		$this->assertSame(['hashtag' => '#nextcloud'], $this->service->getHashtag('nextcloud'));
		$this->assertSame(['hashtag' => '#nextcloud'], $this->service->getHashtag('#nextcloud'));
	}

	public function testGetHashtagPropagatesMisses(): void {
		$this->hashtagsRequest->method('getHashtag')->willThrowException(new HashtagDoesNotExistException());

		$this->expectException(HashtagDoesNotExistException::class);
		$this->service->getHashtag('missing');
	}

	public function testSearchHashtagsDelegatesWithTheAllFlag(): void {
		$this->hashtagsRequest->expects($this->exactly(2))
			->method('searchHashtags')
			->withConsecutive(['next', false], ['next', true])
			->willReturnOnConsecutiveCalls([['hashtag' => '#nextcloud']], []);

		$this->assertSame([['hashtag' => '#nextcloud']], $this->service->searchHashtags('next'));
		$this->assertSame([], $this->service->searchHashtags('next', true));
	}
}
