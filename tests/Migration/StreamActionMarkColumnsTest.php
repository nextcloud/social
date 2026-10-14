<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261013100200;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * When a like or a bookmark was set, as the id the favourites and bookmarks
 * lists are ordered and paged by, and the index each list reads.
 */
class StreamActionMarkColumnsTest extends TestCase {
	/** @return array{columns: array, indexes: array, primary: array} */
	private function actions(): array {
		$shape = MigrationReplay::run(
			[Version1000Date20221118000002::class, Version1000Date20261013100200::class],
			[
				IAppConfig::class => $this->createStub(IAppConfig::class),
				IDBConnection::class => $this->createStub(IDBConnection::class),
			]
		)->shape();

		return $shape['social_stream_act'];
	}

	public function testEachMarkCarriesItsOwnIdOnThePostNidScale(): void {
		$columns = $this->actions()['columns'];

		foreach (['liked_nid', 'bookmarked_nid'] as $column) {
			$this->assertArrayHasKey($column, $columns);
			$this->assertSame(Types::BIGINT, $columns[$column]['type'], $column . ': a post nid is a BIGINT');
			$this->assertTrue($columns[$column]['options']['notnull']);
			$this->assertSame(0, $columns[$column]['options']['default'], $column . ': zero while the mark is not set');
		}
	}

	public function testEachListIsReadAsOneDescendingRange(): void {
		$indexes = [];
		foreach ($this->actions()['indexes'] as $index) {
			$indexes[$index['name']] = $index['columns'];
		}

		$this->assertSame(['actor_id_prim', 'liked', 'liked_nid'], $indexes['social_sa_aln'] ?? null);
		$this->assertSame(['actor_id_prim', 'bookmarked', 'bookmarked_nid'], $indexes['social_sa_abn'] ?? null);
	}
}
