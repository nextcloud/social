<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The replies to a post, against the real database: the public `replies`
 * collection a peer walks and its `totalItems`, which must agree with each
 * other and leave out every reply that is not public, the plain reply count,
 * and the viewer-filtered reply list.
 */
class StreamThreadsTest extends TestCase {
	private const BASE = 'https://cloud.example.org/thrtest';
	private const VIEWER = self::BASE . '/users/viewer';
	private const STRANGER = self::BASE . '/users/stranger';
	private const AUTHOR = 'https://remote.example/thrtest/users/author';
	private const REPLIER = 'https://remote.example/thrtest/users/replier';

	private const SUFFIXES = ['parent', 'public-1', 'public-2', 'followers', 'direct'];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->cleanup();

		foreach ([self::VIEWER, self::STRANGER, self::AUTHOR, self::REPLIER] as $id) {
			$this->cacheActorsRequest->save($this->person($id));
		}

		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/1');
		$follow->setActorId(self::VIEWER);
		$follow->setObjectId(self::REPLIER);
		$follow->setFollowId(self::REPLIER . '/followers');
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);

		$this->note('parent', self::AUTHOR, ACore::CONTEXT_PUBLIC, [self::AUTHOR . '/followers'], '', 50);
		$parent = $this->id('parent');
		$this->note('public-1', self::REPLIER, ACore::CONTEXT_PUBLIC, [self::REPLIER . '/followers'], $parent, 40);
		$this->note('public-2', self::REPLIER, ACore::CONTEXT_PUBLIC, [self::REPLIER . '/followers'], $parent, 30);
		$this->note('followers', self::REPLIER, self::REPLIER . '/followers', [], $parent, 20);
		$this->note('direct', self::REPLIER, self::VIEWER, [], $parent, 10);
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById($this->id($suffix), Note::TYPE);
		}
		foreach ([self::VIEWER, self::STRANGER, self::AUTHOR, self::REPLIER] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
			$this->followsRequest->deleteRelatedId($id);
		}
	}

	private function id(string $suffix): string {
		return self::BASE . '/notes/' . $suffix;
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername(basename($id));
		$person->setAccount(basename($id) . '@' . parse_url($id, PHP_URL_HOST))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal(str_starts_with($id, self::BASE));

		return $person;
	}

	/** Published `$age` seconds ago, so the replies have an order. */
	private function note(string $suffix, string $author, string $to, array $cc, string $inReplyTo, int $age): void {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo($author);
		$note->setTo($to);
		$note->setCcArray($cc);
		$note->setInReplyTo($inReplyTo);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime(time() - $age);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() - $age));
		$this->streamRequest->save($note);
	}

	/**
	 * @param Stream[] $streams
	 * @return string[]
	 */
	private static function names(array $streams): array {
		return array_map(static fn (Stream $stream): string => basename($stream->getId()), $streams);
	}

	public function testTheRepliesCollectionHoldsThePublicRepliesOldestFirst(): void {
		$parent = $this->id('parent');

		$this->assertSame(['public-1', 'public-2'], self::names($this->streamRequest->getPublicRepliesTo($parent, 10)));
		$this->assertSame(2, $this->streamRequest->countPublicRepliesTo($parent), 'totalItems agrees with the pages');
	}

	public function testTheRepliesCollectionPagesByLimitOffsetAndCursor(): void {
		$parent = $this->id('parent');
		$first = $this->streamRequest->getPublicRepliesTo($parent, 1);

		$this->assertSame(['public-1'], self::names($first));
		$this->assertSame(['public-2'], self::names($this->streamRequest->getPublicRepliesTo($parent, 1, 1)));
		$this->assertSame(
			['public-2'],
			self::names($this->streamRequest->getPublicRepliesTo($parent, 10, 0, (string)$first[0]->getNid()))
		);
		$this->assertSame([], $this->streamRequest->getPublicRepliesTo($parent, 0));
		$this->assertSame([], $this->streamRequest->getPublicRepliesTo('', 10));
		$this->assertSame(0, $this->streamRequest->countPublicRepliesTo(''));
	}

	public function testThePlainReplyCountCountsEveryReply(): void {
		$this->assertSame(4, $this->streamRequest->countRepliesTo($this->id('parent')));
		$this->assertSame(0, $this->streamRequest->countRepliesTo($this->id('public-1')));
	}

	/** Newest first, and only what the viewer may read: never a direct reply. */
	public function testAViewerSeesThePublicRepliesAndThoseTheyFollowTheReplierFor(): void {
		$parent = $this->id('parent');

		$this->streamRequest->setViewer($this->person(self::VIEWER));
		$this->assertSame(
			['followers', 'public-2', 'public-1'],
			self::names($this->streamRequest->getRepliesByParentId($parent, 0, 10, true))
		);

		$this->streamRequest->setViewer($this->person(self::STRANGER));
		$this->assertSame(
			['public-2', 'public-1'],
			self::names($this->streamRequest->getRepliesByParentId($parent, 0, 10, true))
		);
	}

	public function testTheViewersReplyListPagesFromAPublicationTime(): void {
		$parent = $this->id('parent');
		$this->streamRequest->setViewer($this->person(self::VIEWER));

		$this->assertSame(['followers'], self::names($this->streamRequest->getRepliesByParentId($parent, 0, 1, true)));
		$this->assertSame(
			['public-2', 'public-1'],
			self::names($this->streamRequest->getRepliesByParentId($parent, time() - 25, 10, true))
		);
	}

	public function testThereAreNoRepliesToNothing(): void {
		$this->expectException(StreamNotFoundException::class);

		$this->streamRequest->getRepliesByParentId('');
	}
}
