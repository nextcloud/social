<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261021000001;
use OCA\Social\Migration\Version1000Date20261021000002;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/**
 * Stores a bounded cursor of status deletions for active streaming clients.
 */
class StatusDeletionEventsTableTest extends TestCase {
	public function testCreatesTheIndexedDeletionCursorTable(): void {
		$schema = MigrationReplay::run([Version1000Date20261021000001::class]);
		$table = $schema->getTable('social_stream_del');

		$this->assertSame(Types::BIGINT, $table->addedColumn('id')[0]);
		$this->assertSame(Types::STRING, $table->addedColumn('status_nid')[0]);
		$this->assertSame(Types::TEXT, $table->addedColumn('hashtags')[0]);
		$this->assertSame(Types::BOOLEAN, $table->addedColumn('local')[0]);
		$this->assertSame(Types::BOOLEAN, $table->addedColumn('media')[0]);
		$this->assertTrue($table->hasPrimaryKey());
	}

	public function testRunningTheMigrationTwiceLeavesTheSchemaAlone(): void {
		$schema = MigrationReplay::run([Version1000Date20261021000001::class]);
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261021000001::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}

	public function testAddsVisibilityAndAudienceToExistingCursor(): void {
		$schema = MigrationReplay::run([Version1000Date20261021000001::class]);
		MigrationReplay::run([Version1000Date20261021000002::class], [], $schema);
		$table = $schema->getTable('social_stream_del');

		$this->assertSame(Types::STRING, $table->addedColumn('visibility')[0]);
		$this->assertSame(16, $table->addedColumn('visibility')[1]['length']);
		$this->assertSame('public', $table->addedColumn('visibility')[1]['default']);
		$this->assertSame(Types::TEXT, $table->addedColumn('recipients')[0]);
		$this->assertFalse($table->addedColumn('recipients')[1]['notnull']);

		$once = $schema->shape();
		MigrationReplay::run([Version1000Date20261021000002::class], [], $schema);
		$this->assertSame($once, $schema->shape());
	}
}
