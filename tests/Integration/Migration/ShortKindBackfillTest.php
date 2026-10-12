<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20261012500100;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `Version1000Date20261012500100` against the real database: the videos
 * already stored are worked out once, the other posts are left as they are,
 * and a second run changes nothing.
 */
class ShortKindBackfillTest extends TestCase {
	private const SHORT = '[{"type":"video","meta":{"duration":30,"original":{"width":1080,"height":1920}}}]';
	private const LONG = '[{"type":"video","meta":{"duration":600,"original":{"width":1080,"height":1920}}}]';
	private const WIDE = '[{"type":"video","meta":{"duration":30,"original":{"width":1920,"height":1080}}}]';
	private const BARE = '[{"type":"video","meta":null}]';

	/** @var string[] stream prims of this run */
	private array $streams = [];

	protected function tearDown(): void {
		foreach ($this->streams as $prim) {
			RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => $prim]);
		}
		parent::tearDown();
	}

	/** @param array<string, mixed> $values */
	private function stream(array $values): string {
		$prim = md5(random_bytes(16));
		$this->streams[] = $prim;
		RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM, array_merge(['id_prim' => $prim, 'short_kind' => null], $values));

		return $prim;
	}

	private function shortKind(string $prim): mixed {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('short_kind')->from(CoreRequestBuilder::TABLE_STREAM)
			->where($qb->expr()->eq('id_prim', $qb->createNamedParameter($prim)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value;
	}

	private function runStep(): void {
		$schema = $this->createStub(ISchemaWrapper::class);
		Server::get(Version1000Date20261012500100::class)
			->postSchemaChange($this->createStub(IOutput::class), static fn () => $schema, []);
	}

	public function testTheVideosAlreadyStoredAreWorkedOutAndRunAgainNothingMoves(): void {
		$short = $this->stream(['media_kind' => 'video', 'attachments' => self::SHORT]);
		$long = $this->stream(['media_kind' => 'video', 'attachments' => self::LONG]);
		$wide = $this->stream(['media_kind' => 'video', 'attachments' => self::WIDE]);
		$bare = $this->stream(['media_kind' => 'video', 'attachments' => self::BARE]);
		// already decided by the write: left as it is, even where it disagrees
		$decided = $this->stream(['media_kind' => 'video', 'attachments' => self::LONG, 'short_kind' => Stream::SHORT_KIND_SHORT]);
		// not a video: not read, and NULL is not a short either
		$photo = $this->stream(['media_kind' => 'image', 'attachments' => '[{"type":"image"}]']);
		$text = $this->stream(['media_kind' => Stream::MEDIA_KIND_NONE, 'attachments' => '[]']);

		for ($run = 1; $run <= 2; $run++) {
			$this->runStep();

			$this->assertSame(Stream::SHORT_KIND_SHORT, $this->shortKind($short), 'run ' . $run . ': a short');
			foreach (['long' => $long, 'wide' => $wide, 'no meta' => $bare] as $what => $prim) {
				$kind = $this->shortKind($prim);
				$this->assertNotNull($kind, 'run ' . $run . ': ' . $what . ' is worked out, not left NULL');
				$this->assertSame(Stream::SHORT_KIND_NONE, (string)$kind, 'run ' . $run . ': ' . $what . ' is not a short');
			}
			$this->assertSame(Stream::SHORT_KIND_SHORT, $this->shortKind($decided), 'run ' . $run . ': a stored answer stays');
			$this->assertNull($this->shortKind($photo), 'run ' . $run . ': a photograph is not read');
			$this->assertNull($this->shortKind($text), 'run ' . $run . ': a text post is not read');
		}
	}
}
