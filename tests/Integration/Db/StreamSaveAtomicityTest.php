<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Saving a post is one fact, against the real database.
 *
 * A post is stored as a row in `social_stream` and then a row per recipient in
 * `social_stream_dest` — and the recipient rows are what put it in a timeline.
 * Written outside a transaction, a failure between the two left a post that
 * exists, is in nobody's timeline, and that nothing ever notices: the recipient
 * insert logged its failure and carried on.
 *
 * These are integration tests because a transaction is a database behaviour;
 * there is nothing to assert about it without one, and the two halves of it —
 * a failure must roll the post back, a duplicate must not — cannot both be
 * checked against a double.
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

	/** @return string[] */
	private function suffixes(): array {
		return ['whole', 'repeated', 'partial', 'twice', 'first', 'clash'];
	}

	private function id(string $suffix): string {
		return self::BASE . '/notes/' . $suffix;
	}

	private function cleanup(): void {
		$prims = array_map(fn (string $suffix): string => md5($this->id($suffix)), $this->suffixes());

		foreach ([
			CoreRequestBuilder::TABLE_STREAM => 'id_prim',
			CoreRequestBuilder::TABLE_STREAM_DEST => 'stream_id',
			CoreRequestBuilder::TABLE_STREAM_TAGS => 'stream_id',
		] as $table => $field) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in(
				$field,
				$qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
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

	/**
	 * Runs `$save` with the request's logger replaced, and hands back what
	 * was logged at error level.
	 *
	 * @return string[]
	 */
	private function errorsLoggedBy(callable $save): array {
		$logger = new class extends AbstractLogger {
			/** @var string[] */
			public array $errors = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				if (in_array($level, [LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY], true)) {
					$this->errors[] = (string)$message;
				}
			}
		};

		$property = new \ReflectionProperty(StreamRequest::class, 'logger');
		/** @var LoggerInterface $original */
		$original = $property->getValue($this->streamRequest);
		$property->setValue($this->streamRequest, $logger);
		try {
			$save();
		} finally {
			$property->setValue($this->streamRequest, $original);
		}

		return $logger->errors;
	}

	/** The nid a stored post was given. */
	private function nidOf(string $id): string {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('nid')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($id))));
		$cursor = $qb->executeQuery();
		$nid = $cursor->fetchOne();
		$cursor->closeCursor();

		return (string)$nid;
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
	 * The ordinary post that the transaction must not cost us.
	 *
	 * Recipients and hashtags repeat all the time — `getToAll()` hands back
	 * `to` alongside `toArray`, the unique index on the recipient rows does not
	 * include the subtype so the same actor in `to` and `cc` collides too, and
	 * a post can simply carry a hashtag twice. Each of those is a refused
	 * insert, and PostgreSQL aborts the whole transaction on any refused
	 * statement: catching the violation and carrying on is not enough there,
	 * the commit fails afterwards and the post is lost. The inserts have to ask
	 * the database to skip the row instead.
	 */
	public function testARepeatedRecipientOrHashtagDoesNotLoseThePost(): void {
		$note = $this->note('repeated');
		$note->setCcArray([Stream::CONTEXT_PUBLIC, self::BASE . '/users/author']);
		$note->setHashtags(['repeated', 'repeated']);

		$this->streamRequest->save($note);

		$this->assertSame(
			1,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', md5($note->getId())),
			'a post whose recipients or hashtags repeat was refused by the database'
		);
		$this->assertGreaterThan(
			0,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id', md5($note->getId())),
			'a post with no recipient rows is a post in nobody timeline'
		);
	}

	/**
	 * The failure this exists for: the recipient rows cannot be written, and
	 * the post must not survive on its own.
	 *
	 * The failure is injected rather than provoked through the schema. Every
	 * collision the schema can be made to produce is a duplicate, and a
	 * duplicate is the one case these writes are meant to shrug off — see the
	 * test above. What is left is a recipient write that fails for a reason
	 * nobody anticipated, which is exactly the case the transaction is for.
	 */
	public function testAPostWhoseRecipientsFailIsNotLeftBehind(): void {
		$note = $this->note('partial');

		$failing = $this->createStub(StreamDestRequest::class);
		$failing->method('generateStreamDest')
			->willThrowException(new \RuntimeException('the recipient rows could not be written'));

		$property = new \ReflectionProperty(StreamRequest::class, 'streamDestRequest');
		$property->setAccessible(true);
		$original = $property->getValue($this->streamRequest);
		$property->setValue($this->streamRequest, $failing);

		try {
			// whether this raises or is swallowed is the caller's business;
			// what matters is what is left behind
			try {
				$this->streamRequest->save($note);
			} catch (\Throwable $e) {
			}
		} finally {
			$property->setValue($this->streamRequest, $original);
		}

		$this->assertSame(
			0,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', md5($note->getId())),
			'the post was stored without its recipients: it exists and is in nobody timeline'
		);
	}

	/**
	 * A post delivered twice — an inbox redelivery, or two copies arriving
	 * together — meets the unique index on `id_prim` the second time. That is
	 * the duplicate the index is there to refuse: the second save stores
	 * nothing and is no error worth an administrator's log.
	 */
	public function testAPostSavedTwiceIsStoredOnceAndQuietly(): void {
		$this->assertTrue($this->streamRequest->save($this->note('twice')));

		$saved = null;
		$errors = $this->errorsLoggedBy(function () use (&$saved): void {
			$saved = $this->streamRequest->save($this->note('twice'));
		});

		$this->assertFalse($saved, 'the second save wrote a row');
		$this->assertSame([], $errors, 'a duplicate delivery was logged as an error');
		$this->assertSame(1, $this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', md5($this->id('twice'))));
	}

	/**
	 * Two posts that draw the same nid: the second meets the primary key, and
	 * is a post that was never stored. It is saved again under a fresh nid
	 * rather than dropped.
	 */
	public function testAPostWhoseNidIsTakenIsStoredUnderAnotherOne(): void {
		$this->assertTrue($this->streamRequest->save($this->note('first')));
		$taken = $this->nidOf($this->id('first'));
		$this->assertNotSame('', $taken);

		$clash = $this->note('clash');
		$clash->setNid($taken);
		$saved = null;
		$errors = $this->errorsLoggedBy(function () use ($clash, &$saved): void {
			$saved = $this->streamRequest->save($clash);
		});

		$this->assertTrue($saved, 'the post whose nid was taken was dropped');
		$this->assertSame([], $errors);
		$this->assertSame(1, $this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', md5($this->id('clash'))));
		$this->assertNotSame($taken, $this->nidOf($this->id('clash')), 'stored under a nid of its own');
		$this->assertSame($taken, $this->nidOf($this->id('first')), 'the post that held the nid keeps it');
		$this->assertGreaterThan(
			0,
			$this->countRows(CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id', md5($this->id('clash'))),
			'stored without the recipient rows that put it in a timeline'
		);
	}
}
