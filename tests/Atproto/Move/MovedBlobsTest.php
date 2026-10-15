<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Move\MovedBlobs;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use PHPUnit\Framework\TestCase;

/**
 * What the person is shown of the records that lose a picture or video a
 * move could not copy, before they go on without it.
 */
class MovedBlobsTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	private static function record(string $collection, string $rkey, array $value, string $localId = ''): StoredRecord {
		$bytes = DagCbor::encode($value);

		return new StoredRecord(self::DID, $collection, $rkey, Cid::forDagCbor($bytes), $bytes, $localId, 1);
	}

	public function testAPostIsNamedByItsDateTextAndWhereItCanBeRead(): void {
		$named = MovedBlobs::named([
			self::record('app.bsky.feed.post', '3kone', ['$type' => 'app.bsky.feed.post', 'text' => '  Sunset at the lake  ', 'createdAt' => '2026-05-01T18:00:00.000Z']),
			self::record('app.bsky.feed.post', '3ktwo', ['$type' => 'app.bsky.feed.post', 'text' => 'here', 'createdAt' => '2026-05-02T08:00:00.000Z'], 'https://social.test/@alice/115'),
		]);

		$this->assertSame([
			['collection' => 'app.bsky.feed.post', 'rkey' => '3kone', 'created_at' => '2026-05-01T18:00:00.000Z', 'text' => 'Sunset at the lake', 'url' => 'https://bsky.app/profile/' . self::DID . '/post/3kone'],
			['collection' => 'app.bsky.feed.post', 'rkey' => '3ktwo', 'created_at' => '2026-05-02T08:00:00.000Z', 'text' => 'here', 'url' => 'https://social.test/@alice/115'],
		], $named);
	}

	public function testALongTextIsCutAndTheProfileHasNoLink(): void {
		$named = MovedBlobs::named([
			self::record('app.bsky.feed.post', '3klong', ['text' => str_repeat('a', 200), 'createdAt' => '2026-05-01T18:00:00.000Z']),
			self::record('app.bsky.actor.profile', 'self', ['displayName' => 'Alice']),
		]);

		$this->assertSame(str_repeat('a', 120) . '…', $named[0]['text']);
		$this->assertSame(['collection' => 'app.bsky.actor.profile', 'rkey' => 'self', 'created_at' => '', 'text' => '', 'url' => ''], $named[1]);
	}

	public function testNoMoreThanAFewAreNamed(): void {
		$records = [];
		for ($i = 0; $i < MovedBlobs::NAMED + 5; $i++) {
			$records[] = self::record('app.bsky.feed.post', '3k' . $i, ['text' => 'post ' . $i]);
		}

		$this->assertCount(MovedBlobs::NAMED, MovedBlobs::named($records));
	}
}
