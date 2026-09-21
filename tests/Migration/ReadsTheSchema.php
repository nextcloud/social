<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCP\IAppConfig;

/**
 * Reads the schema the app installs, for the tests that are about its shape.
 *
 * Each of these used to run one migration and assert what that step added.
 * That step is now one paragraph of `Version1000Date20221118000002`, and the
 * question they were really asking — is this column a nullable smallint, does
 * the queue drain have the index its sort needs — is a question about the
 * schema rather than about the step that got it there. So they ask the schema.
 *
 * That the squash *is* the schema the sixty-seven produced is
 * {@see SquashedSchemaTest}'s business, and that the guards work is too, once
 * for all of them rather than once per step.
 */
trait ReadsTheSchema {
	/** @var array<string, array{columns: array, indexes: array, primary: array}>|null */
	private static ?array $schema = null;

	/** @return array{columns: array, indexes: array, primary: array} */
	private function table(string $name): array {
		if (self::$schema === null) {
			self::$schema = MigrationReplay::run(
				[Version1000Date20221118000002::class],
				[IAppConfig::class => $this->createStub(IAppConfig::class)]
			)->shape();
		}

		$this->assertArrayHasKey($name, self::$schema, $name . ' is not a table this app installs');

		return self::$schema[$name];
	}

	/** @return array{0: string, 1: array} the column's type and its options */
	private function column(string $table, string $name): array {
		$columns = $this->table($table)['columns'];
		$this->assertArrayHasKey($name, $columns, $table . ' has no column ' . $name);

		return [$columns[$name]['type'], $columns[$name]['options']];
	}

	/** @return string[] every column of a table, sorted, since order is not a contract */
	private function columnNames(string $table): array {
		return array_keys($this->table($table)['columns']);
	}

	/**
	 * Asserts a table holds exactly these columns, whatever order either list
	 * happens to be in.
	 *
	 * @param string[] $expected
	 */
	private function assertColumnsAre(string $table, array $expected, string $message = ''): void {
		sort($expected);
		$this->assertSame($expected, $this->columnNames($table), $message);
	}

	/**
	 * Every index of a table, in the shape the old per-step tests asserted:
	 * `[[columns], name, unique]`, sorted by name.
	 *
	 * @return list<array{0: string[], 1: ?string, 2: bool}>
	 */
	private function indexesOf(string $table): array {
		return array_map(
			static fn (array $index): array => [$index['columns'], $index['name'], $index['unique']],
			$this->table($table)['indexes']
		);
	}

	/** @return string[] the columns a table is addressable by */
	private function primaryKeyOf(string $table): array {
		return $this->table($table)['primary'];
	}
}
