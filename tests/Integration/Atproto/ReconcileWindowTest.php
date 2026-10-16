<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The two reads the Bluesky reconcile pass walks its window with, against
 * the real database: newest first, a page below a position at a time, and
 * only what can go to Bluesky or was written here.
 *
 * Both are instance-wide, so what other tests left is filtered out by
 * author and repository before anything is asserted.
 */
class ReconcileWindowTest extends TestCase {
	private const BASE = 'https://social.test/reconcile-window';
	private const AUTHOR = self::BASE . '/users/alice';

	private AtprotoRepoRequest $repositories;
	private StreamRequest $streams;
	private string $did;
	/** @var string[] */
	private array $notes = [];

	protected function setUp(): void {
		$this->repositories = Server::get(AtprotoRepoRequest::class);
		$this->streams = Server::get(StreamRequest::class);
		$this->did = 'did:plc:' . substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz234567', 2)), 0, 24);
	}

	protected function tearDown(): void {
		$this->repositories->deleteRepository($this->did);
		foreach ($this->notes as $id) {
			$this->streams->deleteById($id, Note::TYPE);
		}
		Server::get(CacheActorsRequest::class)->deleteCacheById(self::AUTHOR);
	}

	public function testTheRecordsWrittenHereAreReadNewestFirstAPageBelowAnId(): void {
		$this->record(RecordMapper::POST, '3kwin1', self::BASE . '/1');
		$this->record(RecordMapper::POST, '3kwin2', self::BASE . '/2');
		// brought by a moving repository: it stands for nothing here
		$this->record(RecordMapper::POST, '3kwin3', '');
		$this->record(RecordMapper::LIKE, '3kwin4', self::BASE . '/like/1');
		$this->record(RecordMapper::POST, '3kwin5', self::BASE . '/5');

		$page = $this->ours($this->repositories->getRecordsSince(RecordMapper::POST, time() - 60, 50));
		$this->assertSame([self::BASE . '/5', self::BASE . '/2', self::BASE . '/1'], array_values(array_map(static fn (StoredRecord $r): string => $r->localId, $page)));
		$ids = array_keys($page);
		$sorted = $ids;
		rsort($sorted);
		$this->assertSame($sorted, $ids, 'by record id, highest first');

		$below = $this->ours($this->repositories->getRecordsSince(RecordMapper::POST, time() - 60, 50, $ids[0]));
		$this->assertSame([self::BASE . '/2', self::BASE . '/1'], array_values(array_map(static fn (StoredRecord $r): string => $r->localId, $below)));

		$this->assertSame([], $this->ours($this->repositories->getRecordsSince(RecordMapper::POST, time() + 3600, 50)), 'nothing is younger than the future');
	}

	public function testTheLocalPublicPostsAreReadNewestFirstAPageBelowAStreamNumber(): void {
		$author = new Person();
		$author->setId(self::AUTHOR)->setPreferredUsername('alice');
		$author->setAccount('alice@social.test')
			->setFollowers(self::AUTHOR . '/followers')
			->setInbox(self::AUTHOR . '/inbox')
			->setLocal(true);
		Server::get(CacheActorsRequest::class)->save($author);
		$this->note('/notes/1', Stream::TYPE_PUBLIC, 30);
		$this->note('/notes/2', Stream::TYPE_FOLLOWERS, 20);
		$this->note('/notes/3', Stream::TYPE_PUBLIC, 10);

		$page = $this->byAuthor($this->streams->getLocalPublicSince(time() - 600, 50));
		$this->assertSame([self::BASE . '/notes/3', self::BASE . '/notes/1'], array_map(static fn (Stream $s): string => $s->getId(), $page));
		$this->assertGreaterThan((int)$page[1]->getNid(), (int)$page[0]->getNid());

		$below = $this->byAuthor($this->streams->getLocalPublicSince(time() - 600, 50, (int)$page[0]->getNid()));
		$this->assertSame([self::BASE . '/notes/1'], array_map(static fn (Stream $s): string => $s->getId(), $below));
	}

	private function record(string $collection, string $rkey, string $localId): void {
		$this->repositories->putRecord($this->did, $collection, $rkey, Cid::forRaw($collection . $rkey), 'bytes', $localId);
	}

	private function note(string $path, string $visibility, int $age): void {
		$note = new Note();
		$note->setId(self::BASE . $path);
		$note->setAttributedTo(self::AUTHOR);
		$note->setLocal(true);
		$note->setVisibility($visibility);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setContent('<p>' . $path . '</p>');
		$note->setPublishedTime(time() - $age);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() - $age));
		$this->streams->save($note);
		$this->notes[] = $note->getId();
	}

	/**
	 * @param array<int, StoredRecord> $records
	 * @return array<int, StoredRecord>
	 */
	private function ours(array $records): array {
		return array_filter($records, fn (StoredRecord $r): bool => $r->did === $this->did);
	}

	/**
	 * @param Stream[] $streams
	 * @return list<Stream>
	 */
	private function byAuthor(array $streams): array {
		return array_values(array_filter($streams, static fn (Stream $s): bool => $s->getAttributedTo() === self::AUTHOR));
	}
}
