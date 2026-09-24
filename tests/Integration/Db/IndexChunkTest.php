<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** The repair cursor uses the database's numeric BIGINT ordering and keyset paging. */
class IndexChunkTest extends TestCase {
	private const BASE = 'https://remote.example/index-chunk';
	private const SUFFIXES = ['one', 'two', 'three'];

	private StreamRequest $streamRequest;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();
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
		foreach ([CoreRequestBuilder::TABLE_STREAM_DEST => 'stream_id',
			CoreRequestBuilder::TABLE_STREAM_TAGS => 'stream_id',
			CoreRequestBuilder::TABLE_STREAM => 'id_prim'] as $table => $field) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in(
				$field,
				$qb->createNamedParameter($prims, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
			));
			$qb->executeStatement();
		}
	}

	/**
	 * Walks from this test's own rows rather than from the start of the table.
	 *
	 * The index is every post on the instance, so an empty database was being
	 * assumed: on any instance with posts already on it the first chunk was
	 * somebody else's rows and the counts meant nothing. Starting the walk
	 * just below the first row written here asserts the same three properties
	 * — ordered, exclusive of the cursor, no repeats — against rows the test
	 * put there.
	 */
	public function testIndexChunksAreOrderedExclusiveAndDoNotRepeatRows(): void {
		$written = [];
		foreach (self::SUFFIXES as $index => $suffix) {
			$note = new Note();
			$note->setId($this->id($suffix))
				->setAttributedTo(self::BASE . '/users/author')
				->setPublishedTime(1_700_000_000 + $index)
				->setVisibility(Stream::TYPE_PUBLIC)
				->setTo(Stream::CONTEXT_PUBLIC)
				->setToArray([Stream::CONTEXT_PUBLIC]);
			$this->streamRequest->save($note);
			$written[] = $note->getNid();
		}

		sort($written);
		$from = (string)($written[0] - 1);

		$first = $this->streamRequest->getIndexChunk($from, 2);
		$this->assertCount(2, $first);
		$this->assertLessThan($first[1]['nid'], $first[0]['nid']);
		$this->assertSame(32, strlen($first[0]['id_prim']));
		$this->assertSame([$written[0], $written[1]], array_map('intval', array_column($first, 'nid')));

		$second = $this->streamRequest->getIndexChunk($first[1]['nid'], 2);
		$this->assertNotSame([], $second);
		$this->assertGreaterThan($first[1]['nid'], $second[0]['nid']);
		$this->assertSame($written[2], (int)$second[0]['nid'], 'the cursor row came back a second time');
	}
}
