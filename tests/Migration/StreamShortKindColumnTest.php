<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261012500100;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * `social_stream.short_kind`: a nullable kind column as wide as its siblings,
 * and the `(short_kind, nid)` index `only_short` pages through. The backfill
 * is run against a real database in `Integration\Migration\ShortKindBackfillTest`.
 */
class StreamShortKindColumnTest extends TestCase {
	/** @return array<string, object> */
	private function stand(): array {
		return [IAppConfig::class => $this->createStub(IAppConfig::class)];
	}

	public function testTheColumnIsANullableKindWithAPageIndex(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261012500100::class], [], $schema);

		$table = $schema->getTable('social_stream');
		[$type, $options] = $table->addedColumn('short_kind');
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(7, $options['length'], 'as wide as media_kind and news_kind');
		$this->assertFalse($options['notnull'], 'NULL is a post nobody has looked at');
		$this->assertTrue($table->hasIndex('social_s_sk'));
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], $this->stand());
		MigrationReplay::run([Version1000Date20261012500100::class], [], $schema);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261012500100::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}
}
