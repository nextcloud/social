<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261016103300;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/** How many times a video posted here was watched, and today's dedupe behind the number. */
class VideoViewTablesTest extends TestCase {
	/** @return array<string, array{0: list<string>, 1: bool}> */
	private static function indexes(FakeTable $table): array {
		$indexes = [];
		foreach ($table->addedIndexes() as $index) {
			$indexes[(string)$index['name']] = [$index['columns'], $index['unique']];
		}

		return $indexes;
	}

	public function testOneCounterPerVideo(): void {
		$table = MigrationReplay::run([Version1000Date20261016103300::class])->getTable('social_video_views');

		$this->assertSame(Types::BIGINT, $table->addedColumn('views')[0]);
		$this->assertSame(['id'], $table->addedPrimaryKey());
		$this->assertSame(['social_vviews_s' => [['stream_id_prim'], true]], self::indexes($table));
	}

	/** Unique on (video, viewer hash), which makes a second view the same day a no-op; `day` for the sweep. */
	public function testTheDedupeIsUniquePerVideoAndViewerAndSweptByDay(): void {
		$table = MigrationReplay::run([Version1000Date20261016103300::class])->getTable('social_video_seen');

		$this->assertSame(64, $table->addedColumn('viewer_hash')[1]['length'], 'a sha256 in hex and nothing more');
		$this->assertSame(Types::INTEGER, $table->addedColumn('day')[0]);
		$this->assertSame([
			'social_vseen_sv' => [['stream_id_prim', 'viewer_hash'], true],
			'social_vseen_d' => [['day'], false],
		], self::indexes($table));
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20261016103300::class]);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261016103300::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}
}
