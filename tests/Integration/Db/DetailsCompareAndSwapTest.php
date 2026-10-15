<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The compare-and-swap an edit's `mentions` and the origin's counts are
 * written with, on the database: the blob is replaced only while it is still
 * the one that was read, on SQLite, PostgreSQL and MySQL alike.
 */
class DetailsCompareAndSwapTest extends TestCase {
	private const ID = 'https://remote.example/cas/notes/1';

	private StreamRequest $streamRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->streamRequest->deleteById(self::ID, Note::TYPE);

		$note = new Note();
		$note->setId(self::ID);
		$note->setAttributedTo('https://remote.example/cas/users/alice');
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setContent('<p>hello</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);
	}

	protected function tearDown(): void {
		$this->streamRequest->deleteById(self::ID, Note::TYPE);
		parent::tearDown();
	}

	private function stored(): string|null|false {
		return (new ReflectionMethod(StreamRequest::class, 'storedDetails'))->invoke($this->streamRequest, md5(self::ID));
	}

	private function swap(?string $expected, string $blob): bool {
		return (new ReflectionMethod(StreamRequest::class, 'replaceDetails'))
			->invoke($this->streamRequest, md5(self::ID), $expected, $blob);
	}

	private function setRaw(?string $details): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_STREAM)
			->set('details', $qb->createNamedParameter($details))
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5(self::ID))));
		$qb->executeStatement();
	}

	public function testABlobStillAsReadIsReplaced(): void {
		$read = $this->stored();
		$this->assertIsString($read);

		$this->assertTrue($this->swap($read, '{"likes":1}'));
		$this->assertSame('{"likes":1}', $this->stored());
	}

	public function testABlobChangedSinceItWasReadIsLeftAsItIs(): void {
		$read = $this->stored();
		$this->setRaw('{"quoteRefused":true}');

		$this->assertFalse($this->swap((string)$read, '{"likes":1}'));
		$this->assertSame('{"quoteRefused":true}', $this->stored());
	}

	public function testAnEmptyBlobIsSwappedFromNull(): void {
		$this->setRaw(null);
		$this->assertNull($this->stored());

		$this->assertFalse($this->swap('{}', '{"likes":1}'), 'NULL is not the empty object');
		$this->assertTrue($this->swap(null, '{"likes":1}'));
	}

	/** The whole read-change-write, over a blob another writer changed in between. */
	public function testTheMentionsKeepWhatAnotherWriterStored(): void {
		$this->setRaw('{"quoteRefused":true}');
		$note = new Note();
		$note->setId(self::ID);
		$note->setDetailArray(Details::MENTIONS, [['id' => 'https://remote.example/cas/users/bob']]);

		$this->streamRequest->updateMentions($note);

		$stored = json_decode((string)$this->stored(), true);
		$this->assertTrue($stored['quoteRefused']);
		$this->assertSame('https://remote.example/cas/users/bob', $stored[Details::MENTIONS][0]['id']);
	}

	/**
	 * The origin's counts, stored while an edit's mentions land between the
	 * read and the write (`CountWriter` through `changeDetails()`): the write
	 * that lost is made again over what the other one stored, and both are
	 * kept.
	 */
	public function testTheCountsKeepAMentionStoredWhileTheyWereWritten(): void {
		$this->setRaw('{"remote_likes":1}');
		$note = new Note();
		$note->setId(self::ID);
		$note->setDetailArray(Details::MENTIONS, [['id' => 'https://remote.example/cas/users/bob']]);
		$applied = 0;

		$written = $this->streamRequest->changeDetails(self::ID, function (array $details) use ($note, &$applied): array {
			if ($applied++ === 0) {
				$this->streamRequest->updateMentions($note);
			}
			$details[Details::REMOTE_LIKES] = 7;

			return $details;
		});

		$this->assertSame(2, $applied, 'applied again over the blob as it was after the other write');
		$stored = json_decode((string)$this->stored(), true);
		$this->assertSame($written, $stored);
		$this->assertSame(7, $stored[Details::REMOTE_LIKES]);
		$this->assertSame('https://remote.example/cas/users/bob', $stored[Details::MENTIONS][0]['id']);
	}

	/**
	 * A writer holding the post as it was read — the hidden replies of a
	 * thread, a quote's state — writes its own key only: the origin's counts
	 * and an edit's mentions stored after it read the post stay.
	 */
	public function testAWriterHoldingAnOlderReadKeepsWhatWasStoredSince(): void {
		$this->setRaw('{"quote_state":"pending","reply_state":"pending"}');
		$read = new Note();
		$read->setId(self::ID);
		$read->setDetailsAll(json_decode((string)$this->stored(), true));
		$this->streamRequest->changeDetails(self::ID, static function (array $details): array {
			$details[Details::REMOTE_LIKES] = 7;
			$details[Details::MENTIONS] = [['id' => 'https://remote.example/cas/users/bob']];

			return $details;
		});

		$read->setHiddenReplies(['https://remote.example/cas/notes/2']);
		$read->setDetailsAll(array_diff_key($read->getDetailsAll(), [Details::REPLY_STATE => true]));
		$this->streamRequest->updateDetails($read, Details::HIDDEN_REPLIES, Details::REPLY_STATE);

		$stored = json_decode((string)$this->stored(), true);
		$this->assertSame(7, $stored[Details::REMOTE_LIKES]);
		$this->assertSame('https://remote.example/cas/users/bob', $stored[Details::MENTIONS][0]['id']);
		$this->assertSame(['https://remote.example/cas/notes/2'], $stored[Details::HIDDEN_REPLIES]);
		$this->assertSame('pending', $stored[Details::QUOTE_STATE], 'a key not named is left as stored');
		$this->assertArrayNotHasKey(Details::REPLY_STATE, $stored, 'a named key the post no longer holds is removed');
	}

	public function testAPostThatIsNotThereReadsAsNothing(): void {
		$this->streamRequest->deleteById(self::ID, Note::TYPE);

		$this->assertFalse($this->stored());
		$this->assertFalse($this->swap(null, '{}'));
		$this->assertNull($this->streamRequest->changeDetails(self::ID, static fn (array $details): array => $details));
	}
}
