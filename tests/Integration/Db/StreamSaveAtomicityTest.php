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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Saving a post is one fact, against the real database.
 *
 * A post is stored as a row in `social_stream` and then a row per recipient in
 * `social_stream_dest` — and the recipient rows are what put it in a timeline.
 * Written outside a transaction, a failure between the two left a post that
 * exists, is in nobody's timeline, and that nothing ever notices: the recipient
 * insert logged its failure and carried on.
 *
 * This is an integration test because a transaction is a database behaviour;
 * there is nothing to assert about it without one.
 */
class StreamSaveAtomicityTest extends TestCase {
	private const BASE = 'https://remote.example/atomic';

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
		foreach ([
			CoreRequestBuilder::TABLE_STREAM => 'id_prim',
			CoreRequestBuilder::TABLE_STREAM_DEST => 'stream_id',
		] as $table => $field) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in(
				$field,
				$qb->createNamedParameter(
					[md5($this->id('whole')), md5($this->id('partial'))],
					IQueryBuilder::PARAM_STR_ARRAY
				)
			));
			$qb->executeStatement();
		}
	}

	private function note(string $suffix): Note {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo(self::BASE . '/users/author');
		$note->setPublishedTime(time());
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$note->setTo(Stream::CONTEXT_PUBLIC);
		$note->setToArray([Stream::CONTEXT_PUBLIC]);

		return $note;
	}

	private function countRows(string $table, string $field, string $value): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from($table)
			->where($qb->expr()->eq($field, $qb->createNamedParameter($value)));
		$cursor = $qb->executeQuery();
		$row = $cursor->fetch();
		$cursor->closeCursor();

		return (int)($row['c'] ?? 0);
	}

	public function testAPostAndItsRecipientsAreStoredTogether(): void {
		$this->streamRequest->save($this->note('whole'));

		$this->assertSame(
			1,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', md5($this->id('whole')))
		);
		$this->assertGreaterThan(
			0,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id', md5($this->id('whole'))),
			'a post with no recipient rows is a post in nobody timeline'
		);
	}

	/**
	 * The failure this exists for: the recipient rows cannot be written, and
	 * the post must not survive on its own. Provoked by holding the recipient
	 * row it is about to insert — the unique index refuses the second one, and
	 * `StreamDestRequest::create()` raises rather than logging and carrying on.
	 */
	public function testAPostWhoseRecipientsFailIsNotLeftBehind(): void {
		$note = $this->note('partial');

		// a recipient row that will collide, written before the save
		$qb = $this->connection->getQueryBuilder();
		$qb->insert(CoreRequestBuilder::TABLE_STREAM_DEST)
			->setValue('stream_id', $qb->createNamedParameter(md5($note->getId())))
			->setValue('actor_id', $qb->createNamedParameter(md5(Stream::CONTEXT_PUBLIC)))
			->setValue('type', $qb->createNamedParameter('recipient'))
			->setValue('subtype', $qb->createNamedParameter('to'));
		$qb->executeStatement();

		// whether this raises or is swallowed is the caller's business; what
		// matters is what is left behind
		try {
			$this->streamRequest->save($note);
		} catch (\Throwable $e) {
		}

		$stream = $this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', md5($note->getId()));
		$this->assertSame(
			0,
			$stream,
			'the post was stored without its recipients: it exists and is in nobody timeline'
		);
	}
}
