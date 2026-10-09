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
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A post's stored copy of its attachments, against the real database: the
 * `attachments` column, the `social_stream_media` links from a local post to
 * the documents it carries, and the walks `occ social:media:*` and the media
 * cron page through. None of this SQL had run in a test.
 */
class StreamMediaTest extends TestCase {
	private const BASE = 'https://cloud.example.org/smedia';
	private const AUTHOR = self::BASE . '/users/author';
	private const OTHER = self::BASE . '/users/other';
	private const REMOTE = 'https://remote.example/smedia/users/author';

	private const SUFFIXES = ['one', 'two', 'plain', 'remote-empty', 'remote-full', 'local-empty'];

	private StreamRequest $streamRequest;
	private IDBConnection $connection;
	/** document nids of this run, clear of anything else in the table */
	private int $doc;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->doc = random_int(1_000_000_000, 2_000_000_000);
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
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById($this->id($suffix));
			$qb = $this->connection->getQueryBuilder();
			$qb->delete(CoreRequestBuilder::TABLE_STREAM_MEDIA)
				->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter(md5($this->id($suffix)))));
			$qb->executeStatement();
		}
	}

	private function attachment(int $nid, string $description = ''): MediaAttachment {
		$attachment = new MediaAttachment();
		$attachment->setId((string)$nid)
			->setType('image')
			->setMediaType('image/png')
			->setDescription($description);

		return $attachment;
	}

	/**
	 * A nid is the publication second with a random suffix, so posts that
	 * have to be in nid order are published apart.
	 *
	 * @param MediaAttachment[] $attachments
	 */
	private function note(string $suffix, string $author, bool $local, array $attachments = [], int $age = 0): Note {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo($author);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime(time() - $age);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() - $age));
		$note->setLocal($local);
		$note->setAttachments($attachments);
		$this->streamRequest->save($note);

		return $note;
	}

	/** @return array{nid: string, attachments: string, media_kind: string} */
	private function row(string $suffix): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('nid', 'attachments', 'media_kind')
			->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($this->id($suffix)))));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$this->assertIsArray($row, $suffix . ' is stored');

		return [
			'nid' => (string)$row['nid'],
			'attachments' => (string)$row['attachments'],
			'media_kind' => (string)$row['media_kind'],
		];
	}

	/** @return int[] */
	private function linked(string $suffix): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('doc_nid')
			->from(CoreRequestBuilder::TABLE_STREAM_MEDIA)
			->where($qb->expr()->eq('stream_id_prim', $qb->createNamedParameter(md5($this->id($suffix)))))
			->orderBy('doc_nid', 'asc');
		$result = $qb->executeQuery();
		$nids = [];
		while (($nid = $result->fetchOne()) !== false) {
			$nids[] = (int)$nid;
		}
		$result->closeCursor();

		return $nids;
	}

	private function setAttachmentsColumn(string $suffix, string $value): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_STREAM)
			->set('attachments', $qb->createNamedParameter($value))
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter(md5($this->id($suffix)))));
		$qb->executeStatement();
	}

	/** @return string[] the ids among `$rows` that belong to this test */
	private static function ours(array $rows): array {
		return array_values(array_filter(
			array_column($rows, 'id'), static fn (string $id): bool => str_starts_with($id, self::BASE)
		));
	}

	private function document(int $nid, string $parent, string $description): Document {
		$document = new Document();
		$document->setNid($nid);
		$document->setParentId($this->id($parent));
		$document->setMediaType('image/png');
		$document->setDescription($description);

		return $document;
	}

	public function testALocalPostIsLinkedToTheDocumentsItCarries(): void {
		$this->note('one', self::AUTHOR, true, [$this->attachment($this->doc + 2), $this->attachment($this->doc + 1)]);
		$this->note('remote-full', self::REMOTE, false, [$this->attachment($this->doc + 3)]);

		$this->assertSame([$this->doc + 1, $this->doc + 2], $this->linked('one'));
		$this->assertSame([], $this->linked('remote-full'), 'a remote post names the origin\'s file, not ours');
	}

	public function testTheStoredCopiesArePagedByNidAndSkipPostsWithNone(): void {
		$this->note('one', self::AUTHOR, true, [$this->attachment($this->doc + 1)], 30);
		$this->note('plain', self::AUTHOR, true, [], 20);
		$this->note('two', self::AUTHOR, true, [$this->attachment($this->doc + 2)], 10);
		$this->setAttachmentsColumn('plain', '');
		$first = $this->row('one')['nid'];

		$all = $this->streamRequest->getStoredAttachmentCopies(1000, (string)((int)$first - 1));
		$this->assertSame([$this->id('one'), $this->id('two')], self::ours($all), 'neither "" nor "[]" is a copy');

		$page = $this->streamRequest->getStoredAttachmentCopies(1, (string)((int)$first - 1));
		$this->assertSame([$this->id('one')], array_column($page, 'id'));
		$this->assertSame($first, $page[0]['nid']);
		$this->assertSame((string)($this->doc + 1), json_decode($page[0]['attachments'], true)[0]['id']);

		$after = $this->streamRequest->getStoredAttachmentCopies(1000, $first);
		$this->assertSame([$this->id('two')], self::ours($after), 'the cursor is exclusive');
	}

	public function testAnUploadIsCarriedOnlyByItsAuthorsPostThatNamesIt(): void {
		$this->note('one', self::AUTHOR, true, [$this->attachment($this->doc + 15)]);

		$this->assertTrue($this->streamRequest->carriesUpload(self::AUTHOR, (string)($this->doc + 15)));
		$this->assertFalse($this->streamRequest->carriesUpload(self::OTHER, (string)($this->doc + 15)), 'another author');
		$this->assertFalse($this->streamRequest->carriesUpload(self::AUTHOR, (string)($this->doc + 1)), 'another upload');
		// the nid is matched literally, not as a LIKE pattern
		$this->assertFalse($this->streamRequest->carriesUpload(self::AUTHOR, substr((string)($this->doc + 15), 0, -1) . '_'));
		$this->assertFalse($this->streamRequest->carriesUpload(self::AUTHOR, '%'));
	}

	/**
	 * Swapping a document moves the post's links with it; rebuilding the
	 * same documents' copies leaves them as they are.
	 */
	public function testRewritingTheCopiesRelinksOnlyWhenTheDocumentsChange(): void {
		$this->note('one', self::AUTHOR, true, [$this->attachment($this->doc + 1)]);

		$same = json_encode([$this->attachment($this->doc + 1, 'described now')->asLocal()]);
		$this->streamRequest->setStoredAttachmentCopies($this->id('one'), $same);
		$this->assertSame($same, $this->row('one')['attachments']);
		$this->assertSame([$this->doc + 1], $this->linked('one'));

		$swapped = json_encode([$this->attachment($this->doc + 7)->asLocal(), $this->attachment($this->doc + 5)->asLocal()]);
		$this->streamRequest->setStoredAttachmentCopies($this->id('one'), $swapped);
		$this->assertSame($swapped, $this->row('one')['attachments']);
		$this->assertSame([$this->doc + 5, $this->doc + 7], $this->linked('one'));
	}

	public function testOnlyRemotePostsWrittenWithNoAttachmentsAreRecoveryCandidates(): void {
		$this->note('remote-empty', self::REMOTE, false, [], 30);
		$this->note('remote-full', self::REMOTE, false, [$this->attachment($this->doc + 1)], 20);
		$this->note('local-empty', self::AUTHOR, true, [], 10);
		$first = (int)$this->row('remote-empty')['nid'];

		$candidates = $this->streamRequest->getMissingRemoteAttachments(1000, (string)($first - 1));

		$this->assertSame([$this->id('remote-empty')], self::ours($candidates));
		$this->assertSame('[]', $this->row('remote-empty')['attachments']);
		$this->assertSame([], self::ours($this->streamRequest->getMissingRemoteAttachments(1000, (string)$first)));
	}

	/**
	 * The recovery writes only over a column that is still empty, so a
	 * concurrent import that filled it first wins, and it sets the media kind
	 * the Photos and Videos timelines read in the same statement.
	 */
	public function testRecoveredAttachmentsAreWrittenOnceWithTheirMediaKind(): void {
		$this->note('remote-empty', self::REMOTE, false);
		$this->assertSame(Stream::MEDIA_KIND_NONE, $this->row('remote-empty')['media_kind']);

		$recovered = json_encode([$this->attachment($this->doc + 1)->asLocal()]);
		$this->assertTrue($this->streamRequest->setRecoveredRemoteAttachments($this->id('remote-empty'), $recovered, ''));
		$this->assertSame($recovered, $this->row('remote-empty')['attachments']);
		$this->assertSame('image', $this->row('remote-empty')['media_kind']);

		$later = json_encode([$this->attachment($this->doc + 2)->asLocal()]);
		$this->assertFalse($this->streamRequest->setRecoveredRemoteAttachments($this->id('remote-empty'), $later, ''));
		$this->assertSame($recovered, $this->row('remote-empty')['attachments'], 'the first one stays');
	}

	public function testAFetchedDocumentRebuildsItsOwnCopyOnItsParentAndNoOther(): void {
		$this->note('remote-full', self::REMOTE, false, [
			$this->attachment($this->doc + 1, 'first'), $this->attachment($this->doc + 2, 'second'),
		]);
		$before = json_decode($this->row('remote-full')['attachments'], true);

		$document = $this->document($this->doc + 1, 'remote-full', 'fetched');
		$this->streamRequest->updateAttachments($document);

		$after = json_decode($this->row('remote-full')['attachments'], true);
		$this->assertSame(
			json_decode((string)json_encode($document->convertToMediaAttachment(Server::get(IURLGenerator::class))->asLocal()), true),
			$after[0]
		);
		$this->assertSame('fetched', $after[0]['description']);
		$this->assertSame($before[1], $after[1], 'the other copy is kept exactly as stored');
	}

	public function testADocumentWithNoParentPostChangesNothing(): void {
		$this->note('remote-full', self::REMOTE, false, [$this->attachment($this->doc + 1, 'first')]);
		$before = $this->row('remote-full')['attachments'];

		$this->streamRequest->updateAttachments($this->document($this->doc + 1, 'no-such-post', 'fetched'));

		$this->assertSame($before, $this->row('remote-full')['attachments']);
	}

	/**
	 * An upload is found through the links, not through `parent_id`, and
	 * only on this instance's own posts.
	 */
	public function testAnUploadRebuildsItsCopyOnEveryLocalPostThatCarriesIt(): void {
		$this->note('one', self::AUTHOR, true, [$this->attachment($this->doc + 1, 'old')]);
		$this->note('two', self::AUTHOR, true, [$this->attachment($this->doc + 2), $this->attachment($this->doc + 1, 'old')]);
		$this->note('plain', self::AUTHOR, true, [$this->attachment($this->doc + 2)]);
		$this->note('remote-full', self::REMOTE, false, [$this->attachment($this->doc + 1, 'old')]);
		$plain = $this->row('plain')['attachments'];
		$remote = $this->row('remote-full')['attachments'];

		$document = $this->document($this->doc + 1, 'one', 'new');
		$this->assertSame(2, $this->streamRequest->updateLocalAttachmentCopies($document));

		$this->assertSame('new', json_decode($this->row('one')['attachments'], true)[0]['description']);
		$two = json_decode($this->row('two')['attachments'], true);
		$this->assertSame((string)($this->doc + 2), $two[0]['id']);
		$this->assertSame('new', $two[1]['description']);
		$this->assertSame($plain, $this->row('plain')['attachments'], 'a post without it');
		$this->assertSame($remote, $this->row('remote-full')['attachments'], 'a remote post');

		$this->assertSame(0, $this->streamRequest->updateLocalAttachmentCopies($document), 'nothing left to rebuild');
	}

	public function testTheDocumentsACopyNamesAreItsNumericIdsOnceInOrder(): void {
		$copies = json_encode([['id' => '9'], ['id' => '3'], ['id' => '9'], ['id' => 'abc'], ['id' => ''], 'junk', []]);

		$this->assertSame([3, 9], StreamRequest::documentNidsOf((string)$copies));
		$this->assertSame([], StreamRequest::documentNidsOf('not json'));
	}
}
