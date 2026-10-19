<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CoreRequestBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The row-to-model step reads a stream row by column name. A name that no
 * SELECT provides does not fail — `TArrayTools::get()` hands back the default —
 * so the model quietly comes back with the field blanked, which is how every
 * boost ended up attributed to nobody and could not be deleted through the API.
 *
 * The names are read out of the source with a regular expression rather than by
 * running a query: a query needs a real server and a real database, which this
 * suite has neither of.
 */
class StreamRequestBuilderTest extends TestCase {
	private const SOURCE = __DIR__ . '/../../lib/Db/StreamRequestBuilder.php';

	/**
	 * Columns the parser reads that no stream query selects and that are not
	 * joined in under a prefix.
	 *
	 * @return string[]
	 */
	private function unknownColumnsRead(): array {
		$source = (string)file_get_contents(self::SOURCE);
		preg_match_all(
			"/\\\$this->get(?:Int|Bool|Array)?\(\s*'([^']+)'\s*,\s*\\\$data/",
			$source,
			$matches
		);

		$tables = (new \ReflectionClass(CoreRequestBuilder::class))
			->getStaticPropertyValue('tables');
		$columns = $tables[CoreRequestBuilder::TABLE_STREAM];

		return array_values(array_diff(array_unique($matches[1]), $columns));
	}

	public function testTheParserOnlyReadsColumnsTheStreamQueriesSelect(): void {
		$this->assertSame(
			[],
			$this->unknownColumnsRead(),
			'parseStreamSelectSql() reads a column no stream query selects, so it silently parses as its default'
		);
	}

	/**
	 * The stream-action join is read the same way: a missing alias leaves the
	 * action at its default, which is how an undo dislike came back as a
	 * dislike that was never there.
	 */
	public function testEveryStreamActionColumnTheParserReadsIsSelected(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../lib/Model/StreamAction.php');
		preg_match('/public function importFromDatabase\(array \\$data\): void \{(.*?)\n\t\}/s', $source, $method);
		preg_match_all(
			"/\\\$this->get(?:Int|Bool|Array)?\(\s*'([^']+)'\s*,\s*\\\$data/",
			$method[1] ?? '',
			$matches
		);

		$aliases = array_map(
			static fn (string $column): string => "'streamaction_" . $column . "'",
			array_unique($matches[1])
		);

		foreach ([
			__DIR__ . '/../../lib/Db/CoreRequestBuilder.php',
			__DIR__ . '/../../lib/Db/SocialCrossQueryBuilder.php',
		] as $builder) {
			$builderSource = (string)file_get_contents($builder);
			foreach ($aliases as $alias) {
				$this->assertStringContainsString(
					$alias,
					$builderSource,
					$alias . ' is read from a stream-action row but never selected in ' . basename($builder)
				);
			}
		}
	}
}
