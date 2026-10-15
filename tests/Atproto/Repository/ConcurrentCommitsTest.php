<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Repository;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Protocol\Car;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Repository\RepoWrite;
use OCA\Social\Db\AtprotoEventRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Two commits of one repository that start from the same head run one
 * after the other: the second reads the head the first left, so the
 * stored tree holds both records and the two firehose frames chain, each
 * one's `since` and `prevData` naming the commit before it. A record made
 * once per Social object that the first commit wrote, the second does not
 * write again.
 */
#[AllowMockObjectsWithoutExpectations]
class ConcurrentCommitsTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private InterleavingRepoRequest $store;
	private PrivateKey $key;
	private RepositoryService $service;
	/** @var array<int, array> frame bodies, in the order appended */
	private array $frames = [];

	protected function setUp(): void {
		$this->store = new InterleavingRepoRequest();
		$this->key = PrivateKey::generate(Curve::K256);
		$events = $this->createMock(AtprotoEventRequest::class);
		$events->method('append')->willReturnCallback(function (string $did, string $kind, string $body): int {
			$this->frames[] = DagCbor::decode($body);

			return count($this->frames);
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->service = new RepositoryService($this->store, new EventService($events, $time), new Lexicon(), $this->createMock(IDBConnection::class));
	}

	public function testTwoCommitsFromOneHeadSerialise(): void {
		$first = $this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.actor.profile', ['$type' => 'app.bsky.actor.profile', 'displayName' => 'Alice'], '', 'self')]);
		$this->store->other = function (): void {
			$this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.feed.like', self::like('3kznmn7xqxl22'), '', '3kznmn7xqxl31')]);
		};

		$this->service->write(self::DID, $this->key, [RepoWrite::create('app.bsky.feed.post', self::post('one'), '', '3kznmn7xqxl22')]);

		$this->assertNull($this->store->other, 'the other commit ran');
		$this->assertSame([], $this->service->verify(self::DID, $this->key->publicKey()), 'the stored tree is the tree of both records');
		$this->assertSame(3, $this->store->getHead(self::DID)?->recordCount);
		$this->assertCount(3, $this->frames);
		$revs = [$first->rev, $this->frames[1]['rev'], $this->frames[2]['rev']];
		$this->assertSame([$revs[0], $revs[1]], [$this->frames[1]['since'], $this->frames[2]['since']], 'each frame follows the one before it');
		$this->assertTrue($this->frames[2]['prevData']->equals($this->rootOf($this->frames[1]['commit']->toString())), 'the third commit builds on the second');
		$this->assertSame($this->frames[2]['commit']->toString(), $this->store->getHead(self::DID)?->commitCid);
	}

	public function testAPostPublishedMeanwhileIsNotWrittenAgain(): void {
		$local = 'https://social.test/@alice/1';
		$this->store->other = function () use ($local): void {
			$this->service->write(self::DID, $this->key, [RepoWrite::createOnce('app.bsky.feed.post', self::post('one'), $local, '3kznmn7xqxl22')]);
		};

		$result = $this->service->write(self::DID, $this->key, [
			RepoWrite::createOnce('app.bsky.feed.post', self::post('one'), $local, '3kznmn7xqxl31'),
			RepoWrite::create('app.bsky.feed.threadgate', self::threadgate('3kznmn7xqxl31'), $local, '3kznmn7xqxl31'),
		]);

		$this->assertNull($this->store->other, 'the other commit ran');
		$this->assertNull($result, 'the second commit wrote nothing');
		$this->assertSame(['app.bsky.feed.post/3kznmn7xqxl22'], array_map(
			static fn ($record): string => $record->collection . '/' . $record->rkey,
			$this->store->getRecordsByLocalId($local),
		));
		$this->assertCount(1, $this->frames);
		$this->assertSame([], $this->service->verify(self::DID, $this->key->publicKey()));
	}

	public function testALikeOfAnotherObjectIsWrittenBeside(): void {
		$this->store->other = function (): void {
			$this->service->write(self::DID, $this->key, [RepoWrite::createOnce('app.bsky.feed.like', self::like('3kznmn7xqxl22'), 'https://social.test/like/1')]);
		};

		$result = $this->service->write(self::DID, $this->key, [RepoWrite::createOnce('app.bsky.feed.like', self::like('3kznmn7xqxl22'), 'https://social.test/like/2')]);

		$this->assertNotNull($result);
		$this->assertCount(2, $this->frames);
	}

	private function rootOf(string $commitCid): Cid {
		foreach ($this->frames as $frame) {
			if ($frame['commit']->toString() === $commitCid) {
				return Commit::fromBytes(Car::decode($frame['blocks']->value)['blocks'][$commitCid])->data;
			}
		}
		$this->fail('no frame for ' . $commitCid);
	}

	private static function post(string $text): array {
		return ['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => '2026-10-08T10:00:00.000Z'];
	}

	private static function threadgate(string $rkey): array {
		return ['$type' => 'app.bsky.feed.threadgate', 'post' => 'at://' . self::DID . '/app.bsky.feed.post/' . $rkey, 'allow' => [], 'createdAt' => '2026-10-08T10:00:00.000Z'];
	}

	private static function like(string $rkey): array {
		return ['$type' => 'app.bsky.feed.like', 'subject' => ['uri' => 'at://' . self::DID . '/app.bsky.feed.post/' . $rkey, 'cid' => 'bafyreie5cvv4h45feadgeuwhbcutmh6t2ceseocckahdoe6uat64zmz454'], 'createdAt' => '2026-10-08T10:00:00.000Z'];
	}
}
