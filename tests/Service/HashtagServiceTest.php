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
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\MiscService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The trends cron, and what the trends endpoint reads back.
 *
 * The counting is one grouped query per window; what is left here is the
 * upsert around it — which hashtags get written, and which are left alone.
 */
class HashtagServiceTest extends TestCase {
	private HashtagsRequest|MockObject $hashtagsRequest;
	private StreamRequest|MockObject $streamRequest;
	private IURLGenerator|MockObject $urlGenerator;
	private HashtagService $service;

	protected function setUp(): void {
		$this->hashtagsRequest = $this->createMock(HashtagsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(
				static fn (string $route, array $args): string
					=> 'https://cloud.example/apps/social/timeline/' . $args['path']
			);
		$this->service = new HashtagService(
			$this->hashtagsRequest,
			$this->streamRequest,
			$this->urlGenerator,
			$this->createMock(ConfigService::class),
			$this->createMock(MiscService::class),
		);
	}

	/**
	 * Answers countHashtagsSince() from a set of (hashtag, age in seconds,
	 * how many posts) rows, and records the windows it was asked for.
	 *
	 * @param array<array{0: string, 1: int, 2: int}> $uses
	 * @param int[] $requestedSince
	 */
	private function counting(array $uses, array &$requestedSince): void {
		$this->streamRequest->method('countHashtagsSince')
			->willReturnCallback(function (int $since) use ($uses, &$requestedSince): array {
				$requestedSince[] = $since;

				$counts = [];
				foreach ($uses as [$hashtag, $age, $total]) {
					if (time() - $age >= $since) {
						$counts[$hashtag] = ($counts[$hashtag] ?? 0) + $total;
					}
				}

				return $counts;
			});
	}

	public function testManageHashtagsComputesTrendPerWindowAndUpsertsEachTag(): void {
		$now = time();
		$requestedSince = [];
		$this->counting([
			['nextcloud', 1800, 1],
			['social', 1800, 1],
			['nextcloud', 2 * 86400, 1],
			['php', 5 * 86400, 1],
		], $requestedSince);
		$this->hashtagsRequest->method('getWithAnyTrend')->willReturn([['hashtag' => 'nextcloud', 'trend' => []]]);

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
		$this->streamRequest->method('countHashtagsSince')->willReturn([]);
		$this->hashtagsRequest->method('getWithAnyTrend')->willReturn([['hashtag' => 'old']]);
		$this->hashtagsRequest->expects($this->never())->method('save');
		$this->hashtagsRequest->expects($this->never())->method('update');

		$this->assertSame(0, $this->service->manageHashtags());
	}

	public function testManageHashtagsAggregatesASharedHashtagAcrossNotes(): void {
		$now = time();
		// two posts share #nextcloud, so every window counts it twice
		$requestedSince = [];
		$this->counting([
			['nextcloud', 30, 1],
			['nextcloud', 45, 1],
		], $requestedSince);
		$this->hashtagsRequest->method('getWithAnyTrend')->willReturn([]);

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

	public function testAHashtagThatFellOutOfEveryWindowIsResetToZero(): void {
		// it used to keep its last non-zero score for good, so it stayed
		// "trending" long after the last post that used it
		$requestedSince = [];
		$this->counting([['current', 60, 1]], $requestedSince);
		$this->hashtagsRequest->method('getWithAnyTrend')->willReturn([
			['hashtag' => 'lastweek', 'trend' => ['1h' => 0, '12h' => 0, '1d' => 0, '3d' => 4, '10d' => 9]],
		]);

		$written = [];
		$this->hashtagsRequest->method('update')
			->willReturnCallback(function (string $hashtag, array $trend) use (&$written): void {
				$written[$hashtag] = $trend;
			});
		$this->hashtagsRequest->method('save')
			->willReturnCallback(function (string $hashtag, array $trend) use (&$written): void {
				$written[$hashtag] = $trend;
			});

		$this->service->manageHashtags();

		$this->assertSame(
			['1h' => 0, '12h' => 0, '1d' => 0, '3d' => 0, '10d' => 0],
			$written['lastweek']
		);
	}

	public function testAHashtagWhoseCountsDidNotMoveIsNotWrittenAgain(): void {
		// one write per hashtag per cron pass was the whole cost of this job;
		// on a quiet instance almost nothing has changed since the last one
		$requestedSince = [];
		$this->counting([['steady', 60, 2]], $requestedSince);
		$steady = ['1h' => 2, '12h' => 2, '1d' => 2, '3d' => 2, '10d' => 2];
		$this->hashtagsRequest->method('getWithAnyTrend')->willReturn([
			['hashtag' => 'steady', 'trend' => $steady, 'counters' => $steady],
		]);

		$this->hashtagsRequest->expects($this->never())->method('update');
		$this->hashtagsRequest->expects($this->never())->method('save');

		$this->assertSame(0, $this->service->manageHashtags());
	}

	public function testAHashtagWhoseColumnsLagItsJsonIsWrittenAgain(): void {
		// what an upgrade leaves behind: the JSON trend is the one the previous
		// version wrote and is right, the sortable columns were added zeroed.
		// The counts of a hashtag rarely move between two passes, so skipping on
		// "the JSON has not changed" left those rows out of the trends endpoint
		// and the dashboard widget for good — both read the columns.
		$requestedSince = [];
		$this->counting([['steady', 60, 2]], $requestedSince);
		$steady = ['1h' => 2, '12h' => 2, '1d' => 2, '3d' => 2, '10d' => 2];
		$this->hashtagsRequest->method('getWithAnyTrend')->willReturn([
			[
				'hashtag' => 'steady',
				'trend' => $steady,
				'counters' => ['1h' => 0, '12h' => 0, '1d' => 0, '3d' => 0, '10d' => 0],
			],
		]);

		$written = [];
		$this->hashtagsRequest->expects($this->once())
			->method('update')
			->willReturnCallback(function (string $hashtag, array $trend) use (&$written): void {
				$written[$hashtag] = $trend;
			});
		$this->hashtagsRequest->expects($this->never())->method('save');

		$this->assertSame(1, $this->service->manageHashtags());
		$this->assertSame($steady, $written['steady']);
	}

	public function testTheCountingIsOneGroupedQueryPerWindow(): void {
		// not one hydrated page of posts per window, counted in PHP — which
		// also made every window report the same number on a busy instance
		$this->streamRequest->expects($this->exactly(count(HashtagService::PERIODS)))
			->method('countHashtagsSince')
			->willReturn([]);
		$this->hashtagsRequest->method('getWithAnyTrend')->willReturn([]);

		$this->service->manageHashtags();
	}

	// --- what is trending

	public function testTrendingAsksTheDatabaseForTheWindowAndTheLimit(): void {
		// the ordering and the cut belong to the query: the whole table used to
		// be loaded and sorted here, on every trends request
		$rows = [['hashtag' => 'loud', 'trend' => ['1h' => 9]]];
		$this->hashtagsRequest->expects($this->once())
			->method('getTrending')
			->with('1h', 2)
			->willReturn($rows);

		$this->assertSame($rows, $this->service->getTrending(2, '1h'));
	}

	public function testTrendingFallsBackToTheDefaultWindowForAnUnknownOne(): void {
		$this->hashtagsRequest->expects($this->once())
			->method('getTrending')
			->with(HashtagService::PERIOD_DEFAULT, 10)
			->willReturn([]);

		$this->service->getTrending(10, 'whenever');
	}

	public function testTrendingDefaultsToTheDayWindow(): void {
		$this->hashtagsRequest->expects($this->once())
			->method('getTrending')
			->with(HashtagService::PERIOD_DEFAULT, 10)
			->willReturn([]);

		$this->assertSame([], $this->service->getTrending());
	}

	public function testEveryDeclaredPeriodIsOneTheDatabaseKnowsAColumnFor(): void {
		// the periods the API accepts and the counter columns must not drift
		$this->assertSame(HashtagService::PERIODS, array_keys(HashtagsRequest::TREND_COLUMNS));
		$this->assertContains(HashtagService::PERIOD_DEFAULT, HashtagService::PERIODS);
	}

	public function testGetHashtagLooksUpTheNameAsItIsStored(): void {
		// this asserted the opposite, and the opposite could never match:
		// social_hashtag is filled from social_stream_tag, and
		// Note::fillHashtags() strips the '#' before either table sees one, so
		// the exact-match half of a hashtag search always came up empty
		$this->hashtagsRequest->expects($this->exactly(3))
			->method('getHashtag')
			->with('nextcloud')
			->willReturn(['hashtag' => 'nextcloud']);

		$this->assertSame(['hashtag' => 'nextcloud'], $this->service->getHashtag('nextcloud'));
		$this->assertSame(['hashtag' => 'nextcloud'], $this->service->getHashtag('#nextcloud'));
		$this->assertSame(['hashtag' => 'nextcloud'], $this->service->getHashtag('  #nextcloud '));
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

	public function testATagEntityIsTheShapeAClientReads(): void {
		$this->hashtagsRequest->method('getHashtag')
			->willReturn(['hashtag' => 'nextcloud', 'trend' => ['1h' => 1, '1d' => 7]]);

		$tag = $this->service->tagEntity('nextcloud', true);

		$this->assertSame('nextcloud', $tag['name']);
		$this->assertSame('https://cloud.example/apps/social/timeline/tags/nextcloud', $tag['url']);
		$this->assertTrue($tag['following']);
		$this->assertSame('7', $tag['history'][0]['uses'], 'the default window');
		// this instance counts uses, not distinct accounts, and says so rather
		// than inventing a number
		$this->assertSame('0', $tag['history'][0]['accounts']);
	}

	public function testTheTagIsLookedUpByTheNameItIsStoredUnder(): void {
		// social_hashtag rows come from social_stream_tag, which holds the tag
		// with no leading '#'; asking for '#nextcloud' matches no row
		$asked = [];
		$this->hashtagsRequest->method('getHashtag')
			->willReturnCallback(function (string $hashtag) use (&$asked): array {
				$asked[] = $hashtag;

				return ['hashtag' => $hashtag, 'trend' => []];
			});

		$this->service->tagEntity('nextcloud');

		$this->assertSame(['nextcloud'], $asked);
	}

	public function testATagNobodyHasUsedHasAnEmptyHistoryRatherThanANumber(): void {
		$this->hashtagsRequest->method('getHashtag')
			->willThrowException(new HashtagDoesNotExistException());

		$tag = $this->service->tagEntity('brandnew', false);

		$this->assertSame([], $tag['history']);
		$this->assertFalse($tag['following']);
	}

	public function testFollowingIsLeftOutWhenNobodyIsAsking(): void {
		// `/api/v1/trends/tags` is a public route with no viewer to answer it
		// for, and Mastodon leaves the key out there
		$this->hashtagsRequest->method('getHashtag')
			->willThrowException(new HashtagDoesNotExistException());

		$this->assertArrayNotHasKey('following', $this->service->tagEntity('anything'));
	}

	public function testAnUnknownWindowFallsBackToTheDefaultOne(): void {
		$this->hashtagsRequest->method('getHashtag')
			->willReturn(['hashtag' => 'nextcloud', 'trend' => ['1d' => 4, '10d' => 40]]);

		$tag = $this->service->tagEntity('nextcloud', null, 'fortnight');

		$this->assertSame('4', $tag['history'][0]['uses']);
	}
}
