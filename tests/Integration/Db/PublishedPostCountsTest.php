<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use DateTime;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The two queries behind a local post's counts on Bluesky, against the real
 * database: what of the post's likes, boosts, replies and quotes held here
 * the AppView counts too (`AtprotoRepoRequest::countHeldOnBluesky()`), and
 * which local posts with a Bluesky record the cron asks about
 * (`StreamRequest::getPublishedStreamsDueForCounts()`).
 *
 * The posts are published in this run's own second, decades ahead, so a
 * window starting there holds this test's posts alone.
 */
class PublishedPostCountsTest extends TestCase {
	private const BASE = 'https://cloud.example.org/pubcounts';
	private const ALICE = self::BASE . '/users/alice';
	private const BOB = self::BASE . '/users/bob';
	private const DAVE = self::BASE . '/users/dave';
	private const FEDI = 'https://mastodon.example/pubcounts/users/carol';
	private const DID = 'did:plc:pubcountsaaaaaaaaaaaaaaa';
	private const BSKY = 'https://bsky.app/profile/did:plc:pubcountsbbbbbbbbbbbbbbb';
	private const BSKY2 = 'https://bsky.app/profile/did:plc:pubcountscccccccccccccccc';

	private const SUFFIXES = ['post', 'unpublished', 'early', 'old', 'recent', 'bob-reply'];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private AtprotoRepoRequest $repoRequest;
	private int $second;
	/** @var list<string> */
	private array $actions = [];
	/** @var list<string> */
	private array $streams = [];
	/** @var list<string> */
	private array $records = [];

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->repoRequest = Server::get(AtprotoRepoRequest::class);
		$this->second = 5_000_000_000 + random_int(0, 100_000) * 100;
		$this->cleanup();

		$alice = new Person();
		$alice->setId(self::ALICE)->setPreferredUsername('alice');
		$alice->setAccount('alice@cloud.example.org')
			->setFollowers(self::ALICE . '/followers')
			->setFollowing(self::ALICE . '/following')
			->setInbox(self::ALICE . '/inbox')
			->setOutbox(self::ALICE . '/outbox')
			->setLocal(true);
		$this->cacheActorsRequest->save($alice);
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById($this->id($suffix));
		}
		$this->streamRequest->deleteByPrims(array_map('md5', $this->streams));
		if ($this->actions !== []) {
			RowSeeder::deleteIn(CoreRequestBuilder::TABLE_ACTIONS, 'id_prim', array_map('md5', $this->actions));
		}
		$records = [...$this->records, ...array_map(fn (string $suffix): string => $this->id($suffix), self::SUFFIXES)];
		RowSeeder::deleteIn(CoreRequestBuilder::TABLE_ATPROTO_RECORD, 'local_id_prim', array_map('md5', $records));
		$this->cacheActorsRequest->deleteCacheById(self::ALICE);
		$this->actions = [];
		$this->streams = [];
		$this->records = [];
	}

	private function id(string $suffix): string {
		return self::ALICE . '/statuses/pubcounts-' . $suffix;
	}

	private function post(string $suffix, int $offset, ?int $countedAt = null): void {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo(self::ALICE);
		$note->setToArray([ACore::CONTEXT_PUBLIC]);
		$note->setLocal(true);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime($this->second + $offset);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $this->second + $offset));
		$this->streamRequest->save($note);
		if ($countedAt !== null) {
			$this->streamRequest->markCountsRefreshed($this->id($suffix), new DateTime('@' . $countedAt));
		}
	}

	private function record(string $localId, string $collection): void {
		$this->records[] = $localId;
		$rkey = substr(md5($localId . $collection), 0, 13);
		RowSeeder::insert(CoreRequestBuilder::TABLE_ATPROTO_RECORD, [
			'did' => self::DID,
			'collection' => $collection,
			'rkey' => $rkey,
			'path_prim' => md5(self::DID . '/' . $collection . '/' . $rkey),
			'local_id' => $localId,
			'local_id_prim' => md5($localId),
		]);
	}

	private function action(string $type, string $actor, string $collection = ''): void {
		$id = $actor . '#pubcounts-' . strtolower($type) . '-' . bin2hex(random_bytes(4));
		$this->actions[] = $id;
		RowSeeder::insert(CoreRequestBuilder::TABLE_ACTIONS, [
			'id' => $id, 'id_prim' => md5($id), 'type' => $type,
			'actor_id' => $actor, 'actor_id_prim' => md5($actor),
			'object_id' => $this->id('post'), 'object_id_prim' => md5($this->id('post')),
			'poll_prim' => '',
		]);
		if ($collection !== '') {
			$this->record($id, $collection);
		}
	}

	/** A post naming ours as what it answers or quotes, written as a bare row. */
	private function referrer(string $id, string $column, string $collection = ''): void {
		$this->streams[] = $id;
		RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM, [
			'id' => $id, 'id_prim' => md5($id), 'type' => Note::TYPE, 'local' => false,
			'in_reply_to_prim' => $column === 'in_reply_to_prim' ? md5($this->id('post')) : '',
			'quote_prim' => $column === 'quote_prim' ? md5($this->id('post')) : '',
			'published_time' => new DateTime('@' . $this->second),
		]);
		if ($collection !== '') {
			$this->record($id, $collection);
		}
	}

	public function testWhatCameFromBlueskyAndWhatLocalAccountsWroteThereIsCounted(): void {
		$this->post('post', 0);
		$this->record($this->id('post'), RecordMapper::POST);

		// what the notifications brought from Bluesky
		$this->action(Like::TYPE, self::BSKY);
		$this->action(Like::TYPE, self::BSKY2);
		$this->action(Announce::TYPE, self::BSKY);
		$this->referrer(self::BSKY . '/post/pubcountsreply', 'in_reply_to_prim');
		$this->referrer(self::BSKY2 . '/post/pubcountsquote', 'quote_prim');
		// a local account that wrote its like, boost and reply to Bluesky too
		$this->action(Like::TYPE, self::BOB, RecordMapper::LIKE);
		$this->action(Announce::TYPE, self::BOB, RecordMapper::REPOST);
		$this->referrer(self::BOB . '/statuses/pubcounts-bob-reply', 'in_reply_to_prim', RecordMapper::POST);
		// what the AppView never saw: the Fediverse, a local account not on
		// Bluesky, a like whose record is of another kind
		$this->action(Like::TYPE, self::FEDI);
		$this->action(Announce::TYPE, self::FEDI);
		$this->action(Like::TYPE, self::DAVE);
		$this->action(Like::TYPE, self::DAVE . 'x', RecordMapper::REPOST);
		$this->referrer('https://mastodon.example/pubcounts/notes/reply', 'in_reply_to_prim');
		$this->referrer('https://mastodon.example/pubcounts/notes/quote', 'quote_prim');

		$this->assertSame(
			[Details::LIKES => 3, Details::BOOSTS => 2, Details::REPLIES => 2, Details::QUOTES => 1],
			$this->repoRequest->countHeldOnBluesky($this->id('post'))
		);
	}

	public function testAPostNothingHappenedToHoldsNothing(): void {
		$this->post('post', 0);
		$this->record($this->id('post'), RecordMapper::POST);

		$this->assertSame(
			[Details::LIKES => 0, Details::BOOSTS => 0, Details::REPLIES => 0, Details::QUOTES => 0],
			$this->repoRequest->countHeldOnBluesky($this->id('post'))
		);
	}

	public function testOnlyDuePostsWithARecordInsideTheWindowAreReadOldestFirst(): void {
		$s = $this->second;
		$due = $s + 1000;
		$this->post('early', -10);
		$this->record($this->id('early'), RecordMapper::POST);
		$this->post('recent', 30, $due - 3600);
		$this->record($this->id('recent'), RecordMapper::POST);
		$this->post('post', 20);
		$this->record($this->id('post'), RecordMapper::POST);
		$this->post('old', 10, $due + 60);
		$this->record($this->id('old'), RecordMapper::POST);
		$this->post('unpublished', 15);
		$this->record($this->id('unpublished'), RecordMapper::LIKE);

		$ids = fn (array $posts): array => array_map(static fn (Note $post): string => $post->getId(), $posts);
		$found = $this->streamRequest->getPublishedStreamsDueForCounts(new DateTime('@' . $due), new DateTime('@' . $s));

		$this->assertSame([$this->id('post'), $this->id('recent')], $ids($found), 'stamped when stored, and asked before the due time; oldest first');
		$this->assertTrue($found[0]->isLocal());
		$this->assertSame(self::ALICE, $found[0]->getActor()?->getId(), 'the author comes with the post');
		$this->assertSame(
			[$this->id('post')],
			$ids($this->streamRequest->getPublishedStreamsDueForCounts(new DateTime('@' . $due), new DateTime('@' . $s), 1))
		);
		$this->assertSame(
			[$this->id('old'), $this->id('post'), $this->id('recent')],
			$ids($this->streamRequest->getPublishedStreamsDueForCounts(new DateTime('@' . ($due + 120)), new DateTime('@' . $s))),
			'a later due time takes the post asked in between too'
		);
	}
}
