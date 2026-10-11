<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\TrendService;
use OCA\Social\Tests\Helper\FrozenClock;
use OCA\Social\Tests\Integration\Helper\FreezesTheClock;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The five trend windows walked to their edges on a frozen clock, against the
 * real tables.
 *
 * A status trends on the interactions it had inside the window and a link on
 * the public posts that shared it there, both counted when asked; a hashtag on
 * the public posts that used it, counted by the cron into `social_hashtag`.
 * The first two leave something out on the second it is exactly a window old,
 * the hashtag count still includes it then — each is pinned where it is.
 *
 * The hashtag count is the cron's own pass over every tag, run with the clock
 * on the wall clock's second, so it leaves the instance's trends as the next
 * run of the cron would.
 */
class TrendWindowsTest extends TestCase {
	use FreezesTheClock;

	private const BASE = 'https://remote.example/trend-windows';
	private const AUTHOR = self::BASE . '/users/author';
	private const TAG = 'itest-trend-window';
	private const LINK = 'https://example.org/itest-trend-windows/article';

	protected function setUp(): void {
		parent::setUp();
		$this->cleanup();
		$author = new Person();
		$author->setId(self::AUTHOR)->setPreferredUsername('trend-windows');
		$author->setAccount('trend-windows@remote.example')
			->setInbox(self::AUTHOR . '/inbox')
			->setOutbox(self::AUTHOR . '/outbox')
			->setFollowers(self::AUTHOR . '/followers')
			->setFollowing(self::AUTHOR . '/following');
		Server::get(CacheActorsRequest::class)->save($author);
	}

	protected function tearDown(): void {
		$this->cleanup();
		$this->thawClock();
		parent::tearDown();
	}

	/** @return array<string, array{string, int}> */
	public static function windows(): array {
		return [
			'an hour' => ['1h', HashtagService::TREND_1H],
			'twelve hours' => ['12h', HashtagService::TREND_12H],
			'a day' => ['1d', HashtagService::TREND_1D],
			'three days' => ['3d', HashtagService::TREND_3D],
			'ten days' => ['10d', HashtagService::TREND_10D],
		];
	}

	#[DataProvider('windows')]
	public function testAStatusTrendsUntilItsInteractionsAreAWindowOld(string $period, int $window): void {
		$liked = time();
		$nid = $this->note('liked', $liked);
		$this->like('liked', 30, $liked);
		$clock = $this->freezeClock(new FrozenClock($liked + $window - 1));

		$this->assertContains($nid, $this->trendingNids($period), 'a second before the likes are a window old');

		$clock->advance(1);
		$this->assertNotContains($nid, $this->trendingNids($period), 'on the second they are');
	}

	#[DataProvider('windows')]
	public function testALinkTrendsUntilThePostsSharingItAreAWindowOld(string $period, int $window): void {
		$posted = time();
		foreach (['first', 'second', 'third'] as $suffix) {
			$this->note($suffix, $posted);
			Server::get(StreamCardsRequest::class)->save((new StreamCard(self::BASE . '/notes/' . $suffix, self::LINK))->setTitle('A headline'));
		}
		$clock = $this->freezeClock(new FrozenClock($posted + $window - 1));

		$this->assertSame(3, $this->sharesOfTheLink($period), 'a second before the posts are a window old');

		$clock->advance(1);
		$this->assertSame(0, $this->sharesOfTheLink($period), 'on the second they are');
	}

	/**
	 * One post exactly a window old and one a second older, for every window:
	 * the cron's count takes the first into the window and leaves the second
	 * to the next one out.
	 */
	public function testTheHashtagCountTakesInAPostExactlyAWindowOld(): void {
		$now = time();
		foreach (self::windows() as [$period, $window]) {
			$this->note($period . '-edge', $now - $window, [self::TAG]);
			$this->note($period . '-past', $now - $window - 1, [self::TAG]);
		}
		$this->freezeClock(new FrozenClock($now));

		$this->clocked(HashtagService::class)->manageHashtags();

		$trend = Server::get(HashtagsRequest::class)->getHashtag(self::TAG)['trend'];
		$this->assertSame(['1h' => 1, '12h' => 3, '1d' => 5, '3d' => 7, '10d' => 9], array_map('intval', $trend));
	}

	/** @param string[] $hashtags */
	private function note(string $suffix, int $published, array $hashtags = []): string {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setVisibility('public');
		$note->setContent('<p>' . $suffix . ' ' . implode(' ', $hashtags) . '</p>');
		$note->setHashtags($hashtags);
		$note->setPublishedTime($published);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $published));
		Server::get(StreamRequest::class)->save($note);

		return (string)$note->getNid();
	}

	/** Likes from as many accounts, dated as the moment given. */
	private function like(string $suffix, int $times, int $at): void {
		$objectId = self::BASE . '/notes/' . $suffix;
		for ($i = 0; $i < $times; $i++) {
			$like = new Like();
			$like->setId(self::BASE . '/likes/' . $suffix . '/' . $i);
			$like->setActorId(self::BASE . '/users/fan' . $i);
			$like->setObjectId($objectId);
			Server::get(ActionsRequest::class)->save($like);
		}

		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_ACTIONS)
			->set('creation', $qb->createNamedParameter(new DateTime('@' . $at), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('object_id_prim', $qb->createNamedParameter(md5($objectId))));
		$qb->executeStatement();
	}

	/** @return string[] */
	private function trendingNids(string $period): array {
		return array_map(
			static fn (Stream $status): string => (string)$status->getNid(),
			$this->clocked(TrendService::class)->trendingStatuses($period, 40, 0)
		);
	}

	private function sharesOfTheLink(string $period): int {
		foreach ($this->clocked(TrendService::class)->trendingLinks($period, 40, 0) as $link) {
			if ($link->getCard()->getUrl() === self::LINK) {
				return $link->getShares();
			}
		}

		return 0;
	}

	private function cleanup(): void {
		$streams = Server::get(StreamRequest::class);
		$actions = Server::get(ActionsRequest::class);
		$cards = Server::get(StreamCardsRequest::class);
		$suffixes = ['liked', 'first', 'second', 'third'];
		foreach (self::windows() as [$period]) {
			$suffixes[] = $period . '-edge';
			$suffixes[] = $period . '-past';
		}
		foreach ($suffixes as $suffix) {
			$id = self::BASE . '/notes/' . $suffix;
			$streams->deleteById($id, Note::TYPE);
			$cards->deleteByStreamId($id);
			foreach ($actions->getByObjectId($id) as $action) {
				$actions->delete($action);
			}
		}

		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete(CoreRequestBuilder::TABLE_HASHTAGS)
			->where($qb->expr()->eq('hashtag', $qb->createNamedParameter(self::TAG)));
		$qb->executeStatement();
		Server::get(CacheActorsRequest::class)->deleteCacheById(self::AUTHOR);
	}
}
