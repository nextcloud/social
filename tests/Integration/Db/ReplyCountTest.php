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
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The reply counter on a post, against the real database.
 *
 * It is a recount and not a running total, because the two things that move it
 * — a reply arriving, a reply being deleted — cannot both be expressed as a
 * bump. A delete used not to touch it at all, so a post whose reply had been
 * removed went on claiming one no page could ever show.
 */
class ReplyCountTest extends TestCase {
	private const BASE = 'https://remote.example/replycount';
	private const AUTHOR = self::BASE . '/users/author';

	private const SUFFIXES = ['parent', 'reply-a', 'reply-b'];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();
		// reading a post back joins its author, so there has to be one
		$this->cacheActorsRequest->save($this->author());
	}

	private function author(): Person {
		$person = new Person();
		$person->setId(self::AUTHOR)
			->setPreferredUsername('author');
		$person->setAccount('author@remote.example')
			->setFollowers(self::AUTHOR . '/followers')
			->setFollowing(self::AUTHOR . '/following')
			->setInbox(self::AUTHOR . '/inbox')
			->setOutbox(self::AUTHOR . '/outbox')
			->setLocal(false);

		return $person;
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function id(string $suffix): string {
		return self::BASE . '/notes/' . $suffix;
	}

	private function cleanup(): void {
		$prims = array_map(fn (string $suffix): string => md5($this->id($suffix)), self::SUFFIXES);
		foreach ([
			CoreRequestBuilder::TABLE_STREAM => 'id_prim',
			CoreRequestBuilder::TABLE_STREAM_DEST => 'stream_id',
		] as $table => $field) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in(
				$field, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));
			$qb->executeStatement();
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->delete(CoreRequestBuilder::TABLE_CACHE_ACTORS)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5(self::AUTHOR))));
		$qb->executeStatement();
	}

	private function note(string $suffix, string $inReplyTo = ''): Note {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo('https://www.w3.org/ns/activitystreams#Public');
		$note->setVisibility('public');
		$note->setContent($suffix);
		$note->setInReplyTo($inReplyTo);
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	private function repliesOn(string $suffix): int {
		return $this->streamRequest->getStreamById($this->id($suffix))->getDetailInt('replies');
	}

	public function testARecountCountsTheRepliesThatAreStored(): void {
		$this->note('parent');
		$this->note('reply-a', $this->id('parent'));
		$this->note('reply-b', $this->id('parent'));

		$this->streamRequest->recountReplies($this->id('parent'));

		$this->assertSame(2, $this->repliesOn('parent'));
	}

	public function testARecountAfterADeleteCountsOneFewer(): void {
		$this->note('parent');
		$this->note('reply-a', $this->id('parent'));
		$this->note('reply-b', $this->id('parent'));
		$this->streamRequest->recountReplies($this->id('parent'));

		$this->streamRequest->deleteById($this->id('reply-b'), Note::TYPE);
		$this->streamRequest->recountReplies($this->id('parent'));

		$this->assertSame(1, $this->repliesOn('parent'));
	}

	/**
	 * What the post's own instance said about replies it holds and this one
	 * never will. Nothing here can see them, so the recount carries the number
	 * across rather than replacing it with what is stored locally.
	 */
	public function testARecountKeepsTheRepliesThatLiveOnTheOtherServer(): void {
		$parent = $this->note('parent');
		$parent->setDetailInt('remote_replies', 5);
		$this->streamRequest->updateDetails($parent);
		$this->note('reply-a', $this->id('parent'));

		$this->streamRequest->recountReplies($this->id('parent'));

		$this->assertSame(6, $this->repliesOn('parent'));
	}

	public function testRecountingAPostThisServerDoesNotHaveIsNotAnError(): void {
		$this->streamRequest->recountReplies($this->id('parent'));
		$this->streamRequest->recountReplies('');

		$this->addToAssertionCount(1);
	}
}
