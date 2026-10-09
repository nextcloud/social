<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Db\StreamTagsRequest;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The hashtag rows that put a post in a hashtag timeline, against the real
 * database: written once per tag in the form every reader compares, written
 * again without failing (the write is inside the transaction that stores
 * the post, and PostgreSQL aborts that on a refused duplicate), rewritten by
 * an edit and removed one post at a time.
 *
 * `emptyStreamTags()` is left out: it empties the whole table, which is
 * what `occ social:reset` is for and not something to do to an instance a
 * test shares.
 */
class StreamTagsRequestTest extends TestCase {
	private const BASE = 'https://cloud.example.org/stags/notes/';

	private StreamTagsRequest $streamTagsRequest;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->streamTagsRequest = Server::get(StreamTagsRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (['one', 'two', 'poll', 'boost'] as $suffix) {
			RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM_TAGS, ['stream_id' => md5(self::BASE . $suffix)]);
		}
	}

	/** @param string[] $hashtags */
	private function tagged(Note $stream, string $suffix, array $hashtags): Note {
		$stream->setId(self::BASE . $suffix);
		$stream->setHashtags($hashtags);

		return $stream;
	}

	/** @return string[] */
	private function rows(string $suffix): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('hashtag')
			->from(CoreRequestBuilder::TABLE_STREAM_TAGS)
			->where($qb->expr()->eq('stream_id', $qb->createNamedParameter(md5(self::BASE . $suffix))));
		$result = $qb->executeQuery();
		$tags = [];
		while (($tag = $result->fetchOne()) !== false) {
			$tags[] = (string)$tag;
		}
		$result->closeCursor();
		// sorted here, byte-wise: the database's collation decides where a
		// non-ASCII tag sorts, and it differs between servers
		sort($tags);

		return $tags;
	}

	public function testEachTagIsStoredOnceInTheFormReadersCompare(): void {
		$hashtags = ['#Zebra', 'zebra', '#ZEBRA', '#Ünïcode', '#', ''];
		$this->streamTagsRequest->generateStreamTags($this->tagged(new Note(), 'one', $hashtags));

		$expected = array_values(array_unique(array_filter(array_map(
			static fn (string $tag): string => FollowedTagsRequest::normalise($tag), $hashtags
		))));
		sort($expected);
		$this->assertSame($expected, $this->rows('one'));
		$this->assertContains('zebra', $this->rows('one'));
	}

	public function testStoringTheSameTagsAgainNeitherFailsNorDuplicates(): void {
		$note = $this->tagged(new Note(), 'one', ['#zebra', '#lion']);
		$this->streamTagsRequest->generateStreamTags($note);
		$this->streamTagsRequest->generateStreamTags($note);

		$this->assertSame(['lion', 'zebra'], $this->rows('one'));
	}

	public function testAPollIsTaggedAndABoostIsNot(): void {
		$this->streamTagsRequest->generateStreamTags($this->tagged(new Question(), 'poll', ['#zebra']));
		$boost = new Announce();
		$boost->setId(self::BASE . 'boost');
		$this->streamTagsRequest->generateStreamTags($boost);

		$this->assertSame(['zebra'], $this->rows('poll'));
		$this->assertSame([], $this->rows('boost'));
	}

	/** A tag taken out by an edit leaves the timeline, a tag written in joins it. */
	public function testAnEditRewritesTheTagsOfThatPostAlone(): void {
		$this->streamTagsRequest->generateStreamTags($this->tagged(new Note(), 'one', ['#zebra', '#lion']));
		$this->streamTagsRequest->generateStreamTags($this->tagged(new Note(), 'two', ['#zebra']));

		$this->streamTagsRequest->replaceStreamTags($this->tagged(new Note(), 'one', ['#lion', '#giraffe']));

		$this->assertSame(['giraffe', 'lion'], $this->rows('one'));
		$this->assertSame(['zebra'], $this->rows('two'));
	}

	public function testDeletingTheTagsOfOnePostLeavesTheOthers(): void {
		$this->streamTagsRequest->generateStreamTags($this->tagged(new Note(), 'one', ['#zebra']));
		$this->streamTagsRequest->generateStreamTags($this->tagged(new Note(), 'two', ['#zebra']));

		$this->streamTagsRequest->deleteStreamTags(self::BASE . 'one');
		$this->streamTagsRequest->deleteStreamTags('');
		$this->streamTagsRequest->deleteStreamTags('not-a-url');

		$this->assertSame([], $this->rows('one'));
		$this->assertSame(['zebra'], $this->rows('two'));
	}
}
