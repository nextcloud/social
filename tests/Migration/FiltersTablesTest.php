<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\FiltersRequestBuilder;
use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/** The two tables a keyword filter lives in. */
class FiltersTablesTest extends TestCase {
	use ReadsTheSchema;

	public function testAFilterCarriesEverythingTheApiPromises(): void {
		$this->assertColumnsAre(
			FiltersRequestBuilder::TABLE_FILTERS,
			['id', 'actor_id_prim', 'title', 'contexts', 'action', 'expires_at', 'creation']
		);
	}

	public function testTheOwnerIsTheShapeTheRestOfTheSchemaUses(): void {
		[$type, $options] = $this->column(FiltersRequestBuilder::TABLE_FILTERS, 'actor_id_prim');

		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length'], 'a prim is an md5');
		$this->assertTrue($options['notnull']);
	}

	public function testAFilterMayNeverExpire(): void {
		// NULL is "never", and it is the only reading that does not make every
		// filter made before 1970 expired
		[$type, $options] = $this->column(FiltersRequestBuilder::TABLE_FILTERS, 'expires_at');

		$this->assertSame(Types::DATETIME, $type);
		$this->assertFalse($options['notnull']);
	}

	public function testTheIndexIsTheOneEveryReadUses(): void {
		// every read of a filter — the API and every filtered timeline — asks
		// for one account's filters and nothing else
		$this->assertSame(
			[[['actor_id_prim'], 'social_flt_actor', false]],
			$this->indexesOf(FiltersRequestBuilder::TABLE_FILTERS)
		);
		$this->assertSame(
			[[['filter_id'], 'social_fltkw_filter', false]],
			$this->indexesOf(FiltersRequestBuilder::TABLE_FILTER_KEYWORDS)
		);
	}

	public function testAKeywordNamesItsFilterAndCarriesItsFlag(): void {
		$this->assertColumnsAre(
			FiltersRequestBuilder::TABLE_FILTER_KEYWORDS,
			['id', 'filter_id', 'keyword', 'whole_word', 'creation']
		);

		$this->assertSame(
			Types::BIGINT,
			$this->column(FiltersRequestBuilder::TABLE_FILTER_KEYWORDS, 'filter_id')[0]
		);

		[$type, $options] = $this->column(FiltersRequestBuilder::TABLE_FILTER_KEYWORDS, 'whole_word');
		$this->assertSame(Types::SMALLINT, $type);
		$this->assertSame(0, $options['default']);
	}

	public function testBothRowsCarryAnAutoincrementKeyTheApiAddressesThemBy(): void {
		foreach ([
			FiltersRequestBuilder::TABLE_FILTERS,
			FiltersRequestBuilder::TABLE_FILTER_KEYWORDS,
		] as $table) {
			$this->assertSame(['id'], $this->primaryKeyOf($table), $table);

			[$type, $options] = $this->column($table, 'id');
			$this->assertSame(Types::BIGINT, $type, $table);
			$this->assertTrue($options['autoincrement'], $table);
		}
	}
}
