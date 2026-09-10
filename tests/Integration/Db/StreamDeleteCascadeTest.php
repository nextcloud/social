<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a post, against the real database.
 *
 * A post is not one row: its recipients (which is what puts it in a timeline),
 * the interaction flags on it, its hashtags, its link card, the Like and
 * Announce rows pointing at it and its cached attachments all key on it.
 * deleteById() used to remove the `social_stream` row alone and leave every
 * one of those behind in five tables, where nothing would ever look at them
 * again.
 */
class StreamDeleteCascadeTest extends TestCase {
	private const BASE = 'https://remote.example/cascade';
	private const AUTHOR = self::BASE . '/users/author';
	private const VIEWER = 'https://cloud.example.org/cascade/users/viewer';

	private const SUFFIXES = ['post', 'other', 'kept'];

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

	private function prim(string $suffix): string {
		return md5($this->id($suffix));
	}

	private function cleanup(): void {
		$prims = array_map(fn (string $suffix): string => $this->prim($suffix), self::SUFFIXES);
		foreach ([
			CoreRequestBuilder::TABLE_STREAM => 'id_prim',
			CoreRequestBuilder::TABLE_STREAM_DEST => 'stream_id',
			CoreRequestBuilder::TABLE_STREAM_TAGS => 'stream_id',
			CoreRequestBuilder::TABLE_STREAM_ACTIONS => 'stream_id_prim',
			CoreRequestBuilder::TABLE_STREAM_CARDS => 'stream_id_prim',
			CoreRequestBuilder::TABLE_ACTIONS => 'object_id_prim',
			CoreRequestBuilder::TABLE_CACHE_DOCUMENTS => 'parent_id_prim',
		] as $table => $field) {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in(
				$field, $qb->createNamedParameter($prims, IQueryBuilder::PARAM_STR_ARRAY)
			));
			$qb->executeStatement();
		}
	}

	/** A note with a hashtag, a recipient, a like flag, a card, a Like row and an attachment. */
	private function fullyFurnishedNote(string $suffix): Note {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(self::VIEWER);
		$note->setVisibility('direct');
		$note->setContent('#tagged ' . $suffix);
		$note->setHashtags(['#tagged']);
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		$this->insert(CoreRequestBuilder::TABLE_STREAM_ACTIONS, [
			'actor_id' => self::VIEWER, 'actor_id_prim' => md5(self::VIEWER),
			'stream_id' => $this->id($suffix), 'stream_id_prim' => $this->prim($suffix),
			'liked' => 1, 'boosted' => 0, 'replied' => 0, 'bookmarked' => 0, 'values' => '[]',
		]);
		$this->insert(CoreRequestBuilder::TABLE_STREAM_CARDS, [
			'stream_id_prim' => $this->prim($suffix), 'url' => 'https://example.org/page',
		]);
		$this->insert(CoreRequestBuilder::TABLE_ACTIONS, [
			'id' => $this->id($suffix) . '/like', 'id_prim' => md5($this->id($suffix) . '/like'),
			'type' => 'Like', 'actor_id' => self::VIEWER, 'actor_id_prim' => md5(self::VIEWER),
			'object_id' => $this->id($suffix), 'object_id_prim' => $this->prim($suffix),
		]);
		$this->insert(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, [
			'id' => $this->id($suffix) . '/media', 'id_prim' => md5($this->id($suffix) . '/media'),
			'type' => 'Document', 'account' => '', 'parent_id' => $this->id($suffix),
			'parent_id_prim' => $this->prim($suffix), 'media_type' => 'image/png',
			'mime_type' => 'image/png', 'url' => 'https://remote.example/media.png',
			'local_copy' => '', 'resized_copy' => '', 'meta' => '[]',
		]);

		return $note;
	}

	private function insert(string $table, array $values): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $field => $value) {
			$qb->setValue($field, $qb->createNamedParameter($value));
		}
		$qb->executeStatement();
	}

	private function countRows(string $table, string $field, string $suffix): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')->from($table)
			->where($qb->expr()->eq($field, $qb->createNamedParameter($this->prim($suffix))));
		$cursor = $qb->executeQuery();
		$count = (int)($cursor->fetch()['count'] ?? 0);
		$cursor->closeCursor();

		return $count;
	}

	/** @return array<string, int> table => rows still keyed to the post */
	private function leftovers(string $suffix): array {
		return [
			CoreRequestBuilder::TABLE_STREAM => $this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', $suffix),
			CoreRequestBuilder::TABLE_STREAM_DEST => $this->countRows(CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id', $suffix),
			CoreRequestBuilder::TABLE_STREAM_TAGS => $this->countRows(CoreRequestBuilder::TABLE_STREAM_TAGS, 'stream_id', $suffix),
			CoreRequestBuilder::TABLE_STREAM_ACTIONS => $this->countRows(CoreRequestBuilder::TABLE_STREAM_ACTIONS, 'stream_id_prim', $suffix),
			CoreRequestBuilder::TABLE_STREAM_CARDS => $this->countRows(CoreRequestBuilder::TABLE_STREAM_CARDS, 'stream_id_prim', $suffix),
			CoreRequestBuilder::TABLE_ACTIONS => $this->countRows(CoreRequestBuilder::TABLE_ACTIONS, 'object_id_prim', $suffix),
			CoreRequestBuilder::TABLE_CACHE_DOCUMENTS => $this->countRows(CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, 'parent_id_prim', $suffix),
		];
	}

	public function testEverythingKeyedToAPostGoesWithIt(): void {
		$this->fullyFurnishedNote('post');
		$this->assertNotContains(0, $this->leftovers('post'), 'the fixture is furnished');

		$this->streamRequest->deleteById($this->id('post'));

		$this->assertSame(array_fill_keys(array_keys($this->leftovers('post')), 0), $this->leftovers('post'));
	}

	public function testAPostAddressedByItsPrimIsFoundJustTheSame(): void {
		// PersonInterface reaches for a post by the prim a dest row holds, and
		// hashing that again matched nothing at all
		$this->fullyFurnishedNote('post');

		$this->streamRequest->deleteById($this->prim('post'));

		$this->assertSame(0, $this->countRows(CoreRequestBuilder::TABLE_STREAM, 'id_prim', 'post'));
		$this->assertSame(0, $this->countRows(CoreRequestBuilder::TABLE_STREAM_DEST, 'stream_id', 'post'));
	}

	public function testAGuardedDeleteOfTheWrongTypeTouchesNothing(): void {
		// deleteById($id, Announce::TYPE) on a Note must leave the post whole:
		// the related rows are still in use by it
		$this->fullyFurnishedNote('kept');

		$this->streamRequest->deleteById($this->id('kept'), Announce::TYPE);

		$this->assertNotContains(0, $this->leftovers('kept'));
	}

	public function testDeletingAnAuthorsPostsLeavesNothingOfThemBehind(): void {
		$this->fullyFurnishedNote('post');
		$this->fullyFurnishedNote('other');

		$this->streamRequest->deleteByAuthor(self::AUTHOR);

		foreach (['post', 'other'] as $suffix) {
			$this->assertSame(
				array_fill_keys(array_keys($this->leftovers($suffix)), 0),
				$this->leftovers($suffix)
			);
		}
	}
}
