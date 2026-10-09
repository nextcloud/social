<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * Rows the database still holds in the shapes older releases wrote them,
 * read the way every read reads them and served both ways — as the Mastodon
 * entity a client gets and as the ActivityPub document a peer gets — pinned
 * as golden files (see GoldenFile).
 *
 * The shapes, from the history of the export/import code and the migrations:
 *
 * - 0.11 (to 2026-09-11): `social_stream` had no `tags`, `language`,
 *   `updated`, `quote` or `quote_authorization` column — all five lived only
 *   in `source` (Version1000Date20260912000007, BackfillStreamPostFields); no
 *   `quote_policy` (26b467118); attachments carried no `media_type`
 *   (c929efc34); every count was a key of `details`.
 * - 0.19 (to 2026-10-07): the five columns and `quote_policy` filled, the
 *   attachments typed, the counts still in `details` only.
 * - 0.26 (now): the counts in `count_*` columns (eef00a6c9), which win over
 *   `details`.
 * - `social_cache_actors`: a remote actor's facts read from its `source`
 *   document; a local actor's cached `source` held profile field values as
 *   typed before 0db8ca419 and rendered as HTML after it.
 *
 * A row that is not backfilled yet must read like one that is.
 */
class StoredRowGoldenTest extends TestCase {
	use TActivityPubMocks;
	use TFederationWorld;

	private const POST = 'https://remote.example/users/bob/statuses/109000000000000001';

	protected function setUp(): void {
		$this->installActivityPub(self::$cloud);
		\OC::$server->register(IURLGenerator::class, $this->urls());
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	/** Bob's post as Mastodon sent it, which is what `source` holds. */
	private static function source(): array {
		return [
			'id' => self::POST,
			'type' => 'Note',
			'attributedTo' => 'https://remote.example/users/bob',
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => ['https://remote.example/users/bob/followers'],
			'content' => '<p>first <a href="https://remote.example/tags/beans" class="mention hashtag" rel="tag">#<span>beans</span></a> of the year</p>',
			'contentMap' => ['en' => '<p>first <a href="https://remote.example/tags/beans" class="mention hashtag" rel="tag">#<span>beans</span></a> of the year</p>'],
			'summary' => 'garden',
			'sensitive' => true,
			'published' => '2025-06-01T10:00:00Z',
			'updated' => '2025-06-01T11:00:00Z',
			'quote' => 'https://remote.example/users/bob/statuses/108000000000000001',
			'tag' => [['type' => 'Hashtag', 'href' => 'https://remote.example/tags/beans', 'name' => '#beans']],
			'replies' => ['type' => 'Collection', 'totalItems' => 2],
			'likes' => ['type' => 'Collection', 'totalItems' => 5],
			'shares' => ['type' => 'Collection', 'totalItems' => 1],
		];
	}

	/** The columns every era shares. */
	private static function baseRow(): array {
		return [
			'nid' => '1748772000123456789',
			'id' => self::POST,
			'type' => 'Note',
			'to' => ACore::CONTEXT_PUBLIC,
			'to_array' => '[]',
			'cc' => json_encode(['https://remote.example/users/bob/followers']),
			'bcc' => '[]',
			'content' => self::source()['content'],
			'summary' => 'garden',
			'published' => '2025-06-01T10:00:00Z',
			'published_time' => '2025-06-01 10:00:00',
			'attributed_to' => 'https://remote.example/users/bob',
			'in_reply_to' => '',
			'activity_id' => self::POST . '/activity',
			'object_id' => '',
			'hashtags' => '["beans"]',
			'sensitive' => 1,
			'local' => 0,
			'source' => json_encode(self::source()),
			'cache' => '[]',
			'instances' => '[]',
		];
	}

	/** @return iterable<string, array{string, array}> */
	public static function streamRows(): iterable {
		$attachment = [
			'id' => '41', 'type' => 'image', 'url' => 'https://cloud.example/apps/social/media/a1.jpg',
			'preview_url' => 'https://cloud.example/apps/social/media/a1.jpg', 'remote_url' => 'https://remote.example/media/a1.jpg',
			'description' => 'bean seedlings', 'blurhash' => 'LKO2?U%2Tw=w]~RBVZRi};RPxuwH',
			'meta' => ['original' => ['width' => 800, 'height' => 600]],
		];

		yield '0.11' => ['stored-note-0-11', self::baseRow() + [
			'details' => json_encode(['likes' => 5, 'boosts' => 1, 'replies' => 2]),
			'attachments' => json_encode([$attachment]),
		]];

		yield '0.19' => ['stored-note-0-19', self::baseRow() + [
			'details' => json_encode(['likes' => 5, 'boosts' => 1, 'replies' => 2, 'remote_likes' => 5, 'remote_boosts' => 1, 'remote_replies' => 2]),
			'attachments' => json_encode([$attachment + ['media_type' => 'image/jpeg']]),
			'visibility' => Stream::TYPE_PUBLIC,
			'tags' => json_encode([['type' => 'Hashtag', 'href' => 'https://remote.example/tags/beans', 'name' => '#beans']]),
			'language' => 'en',
			'updated' => '2025-06-01T11:00:00Z',
			'quote' => 'https://remote.example/users/bob/statuses/108000000000000001',
			'quote_authorization' => '',
			'quote_policy' => '',
		]];

		yield '0.26' => ['stored-note-0-26', self::baseRow() + [
			'details' => json_encode(['likes' => 5, 'boosts' => 1, 'replies' => 2, 'remote_likes' => 5, 'remote_boosts' => 1, 'remote_replies' => 2]),
			'attachments' => json_encode([$attachment + ['media_type' => 'image/jpeg', 'hls_url' => null, 'cache_error' => null]]),
			'visibility' => Stream::TYPE_PUBLIC,
			'tags' => json_encode([['type' => 'Hashtag', 'href' => 'https://remote.example/tags/beans', 'name' => '#beans']]),
			'language' => 'en',
			'updated' => '2025-06-01 11:00:00',
			'quote' => 'https://remote.example/users/bob/statuses/108000000000000001',
			'quote_authorization' => '',
			'quote_policy' => '',
			'count_likes' => 7, 'count_boosts' => 1, 'count_replies' => 2, 'count_dislikes' => 0,
		]];
	}

	/**
	 * Each era of row reads as the same post, served to a client and to a
	 * peer; the counts move only where a newer column says so.
	 */
	#[DataProvider('streamRows')]
	public function testAStoredPostOfEachEraIsServedAsTheSamePost(string $golden, array $row): void {
		$post = AP::instance()->getItemFromType('Note');
		$post->importFromDatabase($row);
		$post->setActor($this->fromCacheRow($this->bobRow(), ACore::FORMAT_LOCAL));

		$post->setExportFormat(ACore::FORMAT_LOCAL);
		$status = GoldenFile::wire($post);
		$post->setExportFormat(ACore::FORMAT_ACTIVITYPUB);
		$post->setEntries([]);
		$wire = GoldenFile::wire($post);

		GoldenFile::assertMatches($golden, ['status' => $status, 'activitypub' => $wire]);
	}

	/** Bob's `social_cache_actors` row as a fetch of his actor writes it. */
	private function bobRow(): array {
		return [
			'nid' => '312', 'id' => 'https://remote.example/users/bob', 'account' => 'bob@remote.example',
			'type' => 'Person', 'local' => '0',
			'following' => 'https://remote.example/users/bob/following', 'followers' => 'https://remote.example/users/bob/followers',
			'inbox' => 'https://remote.example/users/bob/inbox', 'shared_inbox' => 'https://remote.example/inbox',
			'outbox' => 'https://remote.example/users/bob/outbox', 'featured' => 'https://remote.example/users/bob/collections/featured',
			'url' => 'https://remote.example/@bob', 'preferred_username' => 'bob', 'name' => 'Bob',
			'summary' => '<p>grows beans</p>', 'public_key' => '-----BEGIN PUBLIC KEY-----\nMFwwDQ==\n-----END PUBLIC KEY-----\n',
			'source' => json_encode([
				'id' => 'https://remote.example/users/bob', 'type' => 'Person', 'manuallyApprovesFollowers' => true,
				'discoverable' => true, 'alsoKnownAs' => ['https://old.example/users/bob'],
				'attachment' => [['type' => 'PropertyValue', 'name' => 'Web', 'value' => '<a href="https://bob.example" rel="me">bob.example</a>']],
				'image' => ['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://remote.example/header.png'],
			]),
			'details' => json_encode(['count' => ['followers' => 3, 'following' => 4, 'post' => 9]]),
			'creation' => '2025-01-02 03:04:05',
		];
	}

	/** @return iterable<string, array{string, array}> */
	public static function actorRows(): iterable {
		yield 'a remote actor, counts in details' => ['stored-actor-remote-details', []];
		yield 'a remote actor, counts in columns' => ['stored-actor-remote-columns', [
			'count_followers' => 30, 'count_following' => 40, 'count_posts' => 90,
		]];
	}

	#[DataProvider('actorRows')]
	public function testACachedRemoteActorOfEachEraIsServedAsTheSameAccount(string $golden, array $columns): void {
		$row = $columns + $this->bobRow();
		$account = GoldenFile::wire($this->fromCacheRow($row, ACore::FORMAT_LOCAL));
		$actor = GoldenFile::wire($this->fromCacheRow($row));

		GoldenFile::assertMatches($golden, ['account' => $account, 'activitypub' => $actor]);
	}

	/** @return iterable<string, array{string, string}> */
	public static function localFieldSources(): iterable {
		yield 'cached before values were rendered' => ['stored-actor-local-typed', 'https://alice.example'];
		yield 'cached after' => ['stored-actor-local-rendered', '<a href="https://alice.example" target="_blank" rel="nofollow noopener noreferrer me" translate="no">https://alice.example</a>'];
	}

	/** A local actor's cached copy serves its fields the same whichever way they were cached. */
	#[DataProvider('localFieldSources')]
	public function testACachedLocalActorServesItsFieldsOnceRendered(string $golden, string $cachedValue): void {
		$alice = $this->localActor();
		$row = $this->cacheRow($alice);
		$source = json_decode($row['source'], true);
		$source['attachment'] = [['type' => 'PropertyValue', 'name' => 'Website', 'value' => $cachedValue]];
		$row['source'] = json_encode($source);

		$served = $this->fromCacheRow($row);
		$served->setDisplayW3ContextSecurity(true);

		GoldenFile::assertMatches($golden, ['attachment' => GoldenFile::wire($served)['attachment']]);
		$this->assertSame([['name' => 'Website', 'value' => 'https://alice.example']], $served->getFields());
	}
}
