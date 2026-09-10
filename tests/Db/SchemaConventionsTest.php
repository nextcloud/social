<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\HashtagsRequest;
use PHPUnit\Framework\TestCase;

/**
 * Schema conventions that a migration can only break once, on somebody's
 * production instance.
 *
 * The index names are read out of the migration sources with a regular
 * expression rather than by running the migrations: a migration needs a real
 * server and a real database, which this suite has neither of.
 */
class SchemaConventionsTest extends TestCase {
	/**
	 * Index names Nextcloud will reject. The limit is a hard 30 characters,
	 * and it is checked when the migration runs — i.e. during somebody's
	 * upgrade.
	 */
	private const MAX_INDEX_NAME = 30;

	/**
	 * Index names from before the app prefixed them. They live in the
	 * database-wide namespace on PostgreSQL, where another app declaring `sa`
	 * or `ts` collides with them — but renaming an existing index is a
	 * migration of its own, and not one worth the risk. Nothing new may join
	 * this list.
	 */
	private const UNPREFIXED_LEGACY = [
		'apopt', 'afoa', 'aoa', 'ipoha', 'object_id_prim', 'in_reply_to_prim',
		'attributed_to_prim', 'sa', 'sat', 'ts', 'sh', 'smlv',
	];

	/** @return array<string, string[]> file => index names */
	private function indexNamesByFile(): array {
		$found = [];
		foreach (glob(__DIR__ . '/../../lib/Migration/*.php') as $file) {
			$source = (string)file_get_contents($file);
			preg_match_all(
				"/add(?:Unique)?Index\(\s*\[[^\]]*\]\s*,\s*'([^']+)'/",
				$source,
				$matches
			);
			$found[basename($file)] = $matches[1];
		}

		return $found;
	}

	public function testEveryIndexNameFitsWhatNextcloudAccepts(): void {
		foreach ($this->indexNamesByFile() as $file => $names) {
			foreach ($names as $name) {
				$this->assertLessThanOrEqual(
					self::MAX_INDEX_NAME,
					strlen($name),
					$file . ' declares the index "' . $name . '" (' . strlen($name)
					. ' characters); Nextcloud rejects anything over ' . self::MAX_INDEX_NAME
				);
			}
		}
	}

	public function testNewIndexesAreNamespacedToTheApp(): void {
		foreach ($this->indexNamesByFile() as $file => $names) {
			foreach ($names as $name) {
				if (in_array($name, self::UNPREFIXED_LEGACY, true)) {
					continue;
				}

				$this->assertStringStartsWith(
					'social_',
					$name,
					$file . ' declares the index "' . $name . '" outside the app namespace:'
					. ' index names are database-wide on PostgreSQL, so prefix it with social_'
				);
			}
		}
	}

	public function testIndexNamesAreNotDeclaredTwice(): void {
		$seen = [];
		foreach ($this->indexNamesByFile() as $file => $names) {
			foreach ($names as $name) {
				$this->assertArrayNotHasKey(
					$name,
					$seen,
					'the index "' . $name . '" is declared in both '
					. ($seen[$name] ?? '?') . ' and ' . $file
				);
				$seen[$name] = $file;
			}
		}
	}

	public function testEveryTableTheAppDeclaresIsOneResetCanEmpty(): void {
		// `occ social:reset` iterates CoreRequestBuilder::$tables. A table that
		// is declared as a constant and left out of it survives a flush and an
		// uninstall — which is how every block, mute and moderation decision
		// used to outlive both.
		$reflection = new \ReflectionClass(CoreRequestBuilder::class);
		$declared = [];
		foreach ($reflection->getConstants() as $name => $value) {
			if (str_starts_with($name, 'TABLE_')) {
				$declared[$name] = $value;
			}
		}

		$this->assertNotEmpty($declared);
		foreach ($declared as $name => $table) {
			$this->assertArrayHasKey(
				$table,
				CoreRequestBuilder::$tables,
				'CoreRequestBuilder::' . $name . ' (' . $table . ') is not in $tables,'
				. ' so occ social:reset leaves it behind'
			);
		}
	}

	public function testTheHashtagTrendColumnsAreDeclaredAsTableColumns(): void {
		$columns = CoreRequestBuilder::$tables[CoreRequestBuilder::TABLE_HASHTAGS];
		foreach (HashtagsRequest::TREND_COLUMNS as $column) {
			$this->assertContains($column, $columns);
		}
	}
}
