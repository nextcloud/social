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

	public function testIndexChunksAreOrderedExclusiveAndDoNotRepeatRows(): void {
		foreach (self::SUFFIXES as $index => $suffix) {
			$note = new Note();
			$note->setId($this->id($suffix))
				->setAttributedTo(self::BASE . '/users/author')
				->setPublishedTime(1_700_000_000 + $index)
				->setVisibility(Stream::TYPE_PUBLIC)
				->setTo(Stream::CONTEXT_PUBLIC)
				->setToArray([Stream::CONTEXT_PUBLIC]);
			$this->streamRequest->save($note);
		}

		$first = $this->streamRequest->getIndexChunk('0', 2);
		$this->assertCount(2, $first);
		$this->assertLessThan($first[1]['nid'], $first[0]['nid']);
		$this->assertSame(32, strlen($first[0]['id_prim']));

		$second = $this->streamRequest->getIndexChunk($first[1]['nid'], 2);
		$this->assertCount(1, $second);
		$this->assertGreaterThan($first[1]['nid'], $second[0]['nid']);
		$this->assertSame([], $this->streamRequest->getIndexChunk($second[0]['nid'], 2));
	}
}
