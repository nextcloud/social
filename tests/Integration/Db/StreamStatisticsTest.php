<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The grouped queries behind the statistics page, the profile's activity
 * chart and the admin's activity numbers, against the real database: each
 * one counts what it says it counts, public posts only where a stranger
 * reads the answer, and the account's own replies to itself never as a
 * conversation.
 *
 * Everything is published in this run's own second, decades ahead, so the
 * instance-wide count from that second on counts this test's posts alone.
 */
class StreamStatisticsTest extends TestCase {
	private const BASE = 'https://cloud.example.org/sstats';
	private const ALICE = self::BASE . '/users/alice';
	private const BOB = 'https://remote.example/sstats/users/bob';
	private const CAROL = 'https://remote.example/sstats/users/carol';

	private const SUFFIXES = ['a1', 'a2', 'a3', 'a4', 'a5', 'b1', 'b2', 'b3', 'c1'];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private int $second;
	/** @var string[] prims of the boosts */
	private array $boosts = [];
	/** @var string[] prims of the likes */
	private array $likes = [];
	/** @var string[] prims of the notifications */
	private array $notifications = [];

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->second = 5_000_000_000 + random_int(0, 100_000) * 100;
		$this->cleanup();

		$bob = new Person();
		$bob->setId(self::BOB)->setPreferredUsername('bob');
		$bob->setAccount('bob@remote.example');
		$this->cacheActorsRequest->save($bob);

		$public = [ACore::CONTEXT_PUBLIC];
		$this->post('b1', self::BOB, 0, $public);
		$this->post('a1', self::ALICE, 1, $public, ['zebra', 'lion']);
		$this->post('a2', self::ALICE, 2, $public, ['zebra']);
		$this->post('a3', self::ALICE, 3, [self::ALICE . '/followers'], ['secret']);
		$this->post('a4', self::ALICE, 4, $public, [], 'b1');
		$this->post('b2', self::BOB, 5, $public, [], 'a1');
		$this->post('b3', self::BOB, 6, $public, [], 'a2');
		$this->post('c1', self::CAROL, 7, $public, [], 'a1');
		$this->post('a5', self::ALICE, 8, $public, [], 'a1');

		$this->boost(self::BOB, 'a1');
		$this->boost(self::BOB, 'a1');
		$this->boost(self::CAROL, 'a2');
		$this->like(self::BOB, 'a1');
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById($this->id($suffix));
		}
		$this->streamRequest->deleteByPrims($this->boosts);
		$this->streamRequest->deleteByPrims($this->notifications);
		RowSeeder::deleteIn(CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id', $this->notifications);
		RowSeeder::deleteIn(CoreRequestBuilder::TABLE_ACTIONS, 'id_prim', $this->likes);
		$this->cacheActorsRequest->deleteCacheById(self::BOB);
	}

	private function id(string $suffix): string {
		return self::BASE . '/notes/' . $suffix;
	}

	/**
	 * @param string[] $to
	 * @param string[] $hashtags
	 */
	private function post(string $suffix, string $author, int $offset, array $to, array $hashtags = [], string $inReplyTo = ''): void {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo($author);
		$note->setToArray($to);
		$note->setLocal($author === self::ALICE);
		$note->setHashtags($hashtags);
		$note->setInReplyTo(($inReplyTo === '') ? '' : $this->id($inReplyTo));
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime($this->second + $offset);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $this->second + $offset));
		$this->streamRequest->save($note);
	}

	private function boost(string $actor, string $of): void {
		$id = $actor . '/boosts/' . bin2hex(random_bytes(4));
		$this->boosts[] = md5($id);
		RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM, [
			'id' => $id, 'id_prim' => md5($id), 'type' => Announce::TYPE, 'local' => false,
			'attributed_to' => $actor, 'attributed_to_prim' => md5($actor),
			'object_id' => $this->id($of), 'object_id_prim' => md5($this->id($of)),
			'published_time' => new \DateTime('@' . $this->second),
		]);
	}

	private function like(string $actor, string $of): void {
		$id = $actor . '/likes/' . bin2hex(random_bytes(4));
		$this->likes[] = md5($id);
		RowSeeder::insert(CoreRequestBuilder::TABLE_ACTIONS, [
			'id' => $id, 'id_prim' => md5($id), 'type' => Like::TYPE,
			'actor_id' => $actor, 'actor_id_prim' => md5($actor),
			'object_id' => $this->id($of), 'object_id_prim' => md5($this->id($of)),
		]);
	}

	/** A notification of one kind raised by an account for a recipient. */
	private function notification(string $recipient, string $from, string $subType, int $offset): void {
		$id = $from . '/notification+' . $subType . '/' . bin2hex(random_bytes(4));
		$this->notifications[] = md5($id);
		RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM, [
			'id' => $id, 'id_prim' => md5($id), 'type' => SocialAppNotification::TYPE, 'subtype' => $subType,
			'local' => true, 'attributed_to' => $from, 'attributed_to_prim' => md5($from),
			'published_time' => new \DateTime('@' . ($this->second + $offset)),
		]);
		RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM_DEST, [
			'stream_id' => md5($id), 'actor_id' => md5($recipient), 'type' => 'notif', 'subtype' => '',
		]);
	}

	public function testTheInstancesOwnActivityCountsLocalStatusesAndTheirAuthors(): void {
		$this->assertSame(['posts' => 5, 'authors' => 1], $this->streamRequest->localActivitySince($this->second));
		$this->assertSame(['posts' => 3, 'authors' => 1], $this->streamRequest->localActivitySince($this->second + 3));
	}

	/** Public posts only: the chart is drawn for strangers too. */
	public function testTheActivityChartHasThePublicationTimesOfPublicPostsNewestFirst(): void {
		$s = $this->second;

		$this->assertSame([$s + 8, $s + 4, $s + 2, $s + 1], $this->streamRequest->publishedTimesByAuthor(self::ALICE, $s));
		$this->assertSame([$s + 8, $s + 4], $this->streamRequest->publishedTimesByAuthor(self::ALICE, $s, 2));
		$this->assertSame([$s + 8, $s + 4], $this->streamRequest->publishedTimesByAuthor(self::ALICE, $s + 3));
		$this->assertSame([], $this->streamRequest->publishedTimesByAuthor('', $s));
	}

	/** A tag used only in followers-only posts is a fact about those posts. */
	public function testTheTopHashtagsComeFromPublicPostsMostUsedFirst(): void {
		$this->assertSame(
			[['name' => 'zebra', 'count' => 2], ['name' => 'lion', 'count' => 1]],
			$this->streamRequest->topHashtagsByAuthor(self::ALICE, $this->second)
		);
		$this->assertSame(
			[['name' => 'zebra', 'count' => 2]],
			$this->streamRequest->topHashtagsByAuthor(self::ALICE, $this->second, 1)
		);
		$this->assertSame(
			[['name' => 'zebra', 'count' => 1]],
			$this->streamRequest->topHashtagsByAuthor(self::ALICE, $this->second + 2)
		);
	}

	/**
	 * Who replied to the account, and whom it replied to, by handle; a reply
	 * to itself is not a conversation with anybody.
	 */
	public function testConversationPartnersAreCountedInBothDirections(): void {
		$this->assertSame([
			['id' => self::BOB, 'account' => 'bob@remote.example', 'replies' => 2],
			['id' => self::CAROL, 'account' => 'carol@remote.example', 'replies' => 1],
		], $this->streamRequest->countConversationPartners(self::ALICE, StreamRequest::PARTNERS_INBOUND));

		$this->assertSame(
			[['id' => self::BOB, 'account' => 'bob@remote.example', 'replies' => 1]],
			$this->streamRequest->countConversationPartners(self::ALICE, StreamRequest::PARTNERS_OUTBOUND)
		);

		$this->assertEqualsCanonicalizing(
			[self::BOB, self::CAROL],
			array_column($this->streamRequest->countConversationPartners(
				self::ALICE, StreamRequest::PARTNERS_INBOUND, $this->second + 6
			), 'id')
		);
		$this->assertCount(1, $this->streamRequest->countConversationPartners(self::ALICE, StreamRequest::PARTNERS_INBOUND, 0, 1));
	}

	/** Once per account and post, whoever boosted twice. */
	public function testTheBoostersOfEachPostAreNamedOnce(): void {
		$this->assertEquals(
			[$this->id('a1') => [self::BOB], $this->id('a2') => [self::CAROL]],
			$this->streamRequest->boostersOf([$this->id('a1'), $this->id('a2'), $this->id('a3'), 'not-a-url'])
		);
		$this->assertSame([], $this->streamRequest->boostersOf(['not-a-url']));
		$this->assertSame([], $this->streamRequest->boostersOf([]));
	}

	/** Every like, boost and reply once for whoever made it. */
	public function testTheReactionsToPostsAreCountedByWhoMadeThem(): void {
		$reactions = $this->streamRequest->reactionsOf([$this->id('a1'), $this->id('a2'), $this->id('a3')]);
		ksort($reactions);

		// Bob: two boosts and a like of a1, replies b2 and b3; Carol: the
		// boost of a2 and the reply c1; Alice's own reply a5 to a1
		$this->assertSame([self::ALICE => 1, self::BOB => 5, self::CAROL => 2], $reactions);
		$this->assertSame([], $this->streamRequest->reactionsOf([]));
	}

	/** Each account once, the account's own notifications only, from a moment on. */
	public function testTheAccountsThatRaisedOneKindOfNotificationAreCountedOnce(): void {
		$kind = Stream::SUBTYPE_BLUESKY_STARTER_PACK;
		$this->notification(self::ALICE, self::BOB, $kind, 0);
		$this->notification(self::ALICE, self::BOB, $kind, 1);
		$this->notification(self::ALICE, self::CAROL, $kind, 5);
		$this->notification(self::BOB, self::CAROL, $kind, 5);
		$this->notification(self::ALICE, self::CAROL, 'Something else', 5);

		$this->assertSame(2, $this->streamRequest->countNotifiersOf(self::ALICE, $kind));
		$this->assertSame(1, $this->streamRequest->countNotifiersOf(self::ALICE, $kind, $this->second + 3));
		$this->assertSame(1, $this->streamRequest->countNotifiersOf(self::BOB, $kind));
	}
}
