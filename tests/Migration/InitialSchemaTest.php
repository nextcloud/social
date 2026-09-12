<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20221118000001;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The step that creates the app: 14 tables, written in 2022 and untested until
 * the wave that then squashed the three 2023 repairs into it.
 *
 * This is what stands in for those repairs now. A mistake here does not fail
 * in CI, it fails on somebody's `occ upgrade`, so what is asserted is the end
 * state the four steps used to produce between them: which tables exist, which
 * key each is addressable by, the columns the repairs added, and that a re-run
 * on an instance that already has them asks for nothing.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * outside this suite.
 */
class InitialSchemaTest extends TestCase {
	use RecordsSchemaChanges;

	/** Every table the step creates, in the order it creates them. */
	private const TABLES = [
		'social_action',
		'social_actor',
		'social_cache_actor',
		'social_cache_doc',
		'social_client',
		'social_follow',
		'social_hashtag',
		'social_instance',
		'social_req_queue',
		'social_stream',
		'social_stream_act',
		'social_stream_dest',
		'social_stream_queue',
		'social_stream_tag',
	];

	/** The two side tables the step creates with no key of their own. */
	private const WITHOUT_PRIMARY_KEY = ['social_stream_dest', 'social_stream_tag'];

	/** @var array<string, array<string, array{string, array}>> table => column => [type, options] */
	private array $added = [];
	/** @var array<string, array<int, array{string[], string, bool}>> table => [columns, name, unique] */
	private array $indexes = [];
	/** @var array<string, string[]> table => primary key columns */
	private array $primaryKeys = [];
	/** @var string[] the tables the step created, in order */
	private array $created = [];

	/** @param string[] $existing tables the instance already has */
	private function schemaClosure(array $existing = []): Closure {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')
			->willReturnCallback(static fn (string $name): bool => in_array($name, $existing, true));
		$schema->method('createTable')
			->willReturnCallback(function (string $name) {
				$this->created[] = $name;

				return $this->recordTable($name);
			});

		return static fn (): ISchemaWrapper => $schema;
	}

	/** @param string[] $existing */
	private function migrate(array $existing = []): void {
		(new Version1000Date20221118000001())
			->changeSchema($this->createMock(IOutput::class), $this->schemaClosure($existing), []);
		$this->harvestSchemaChangesByTable();
	}

	public function testItCreatesTheFourteenTablesTheAppIsBuiltOn(): void {
		$this->migrate();

		$this->assertSame(self::TABLES, $this->created);
	}

	public function testEveryTableItCreatesIsStillOneTheCodeNames(): void {
		$this->migrate();

		$named = [];
		foreach ((new \ReflectionClass(CoreRequestBuilder::class))->getConstants() as $name => $value) {
			if (str_starts_with($name, 'TABLE_')) {
				$named[] = $value;
			}
		}

		foreach ($this->created as $table) {
			// a table created here that nothing names any more would be dead
			// weight on every fresh install, which is what the 14 dropped
			// `social_3_*` tables were
			$this->assertContains($table, $named, $table);
		}
	}

	public function testEveryTableButTheTwoSideTablesIsAddressableByAPrimaryKey(): void {
		$this->migrate();

		foreach (self::TABLES as $table) {
			if (in_array($table, self::WITHOUT_PRIMARY_KEY, true)) {
				continue;
			}

			$this->assertNotEmpty($this->primaryKeys[$table] ?? [], $table);
		}
	}

	public function testTheTwoHighestInsertRateSideTablesShippedWithoutAPrimaryKey(): void {
		$this->migrate();

		// Recorded because it is the reason both tables later grew an
		// autoincrement `id` that nothing reads: they had no key at all, only
		// a unique index over the columns that identify a row.
		foreach (self::WITHOUT_PRIMARY_KEY as $table) {
			$this->assertSame([], $this->primaryKeys[$table], $table);
			$this->assertNotEmpty($this->indexes[$table], $table);
		}
	}

	public function testTheActorTablesAreKeyedByTheHashedActivityPubId(): void {
		$this->migrate();

		// `id_prim` is the md5 of the ActivityPub id: the id itself is a URL,
		// too long for an indexed key on MySQL, and every join in lib/Db uses
		// the hashed form
		foreach (['social_actor', 'social_action', 'social_follow'] as $table) {
			$this->assertSame(['id_prim'], $this->primaryKeys[$table], $table);
			$this->assertArrayHasKey('id_prim', $this->added[$table], $table);
		}
	}

	public function testTheThreeTablesWithNumericIdsKeepTheHashedIdUnique(): void {
		$this->migrate();

		// `nid` is an autoincrement number, so the hashed ActivityPub id needs
		// a unique index of its own or the same object could be stored twice
		foreach (['social_stream', 'social_cache_actor'] as $table) {
			$this->assertSame(['nid'], $this->primaryKeys[$table], $table);
			$this->assertContains(
				[['id_prim'], null, true],
				$this->indexes[$table],
				$table
			);
		}
	}

	public function testTheCacheTablesCarryTheColumnsTheRepairStepsUsedToAdd(): void {
		$this->migrate();

		// `social_cache_doc.account` was the last thing the 2023 repairs still
		// did for a new instance; the rest of their work this step already
		// produced directly.
		foreach (['account', 'meta', 'blurhash', 'description'] as $column) {
			$this->assertArrayHasKey($column, $this->added['social_cache_doc'], $column);
			[, $options] = $this->added['social_cache_doc'][$column];
			// PostgreSQL refuses a NOT NULL column on a populated table unless
			// it has a default; MySQL quietly invents one
			if ($options['notnull'] ?? false) {
				$this->assertArrayHasKey('default', $options, $column);
			}
		}

		$this->assertArrayHasKey('details_update', $this->added['social_cache_actor']);
		$this->assertArrayHasKey('visibility', $this->added['social_stream']);
	}

	public function testTheReNumberedTablesKeepTheHashedIdUnique(): void {
		$this->migrate();

		// the 2023 repairs re-keyed these from `id_prim` onto an autoincrement
		// `nid`, which means the hashed ActivityPub id needs a unique index of
		// its own or the same object could be stored twice
		foreach (['social_stream', 'social_cache_actor'] as $table) {
			$this->assertSame(['nid'], $this->primaryKeys[$table], $table);
			$this->assertContains([['id_prim'], null, true], $this->indexes[$table], $table);
		}

		$this->assertSame(['nid'], $this->primaryKeys['social_cache_doc']);
	}

	public function testARerunOnAnInstanceThatHasThemAsksForNothing(): void {
		$this->migrate(self::TABLES);

		$this->assertSame([], $this->created);
		$this->assertSame([], $this->added);
	}

	public function testAnUpgradeInterruptedPartWayThroughCreatesOnlyWhatIsMissing(): void {
		$this->migrate(['social_action', 'social_actor', 'social_cache_actor']);

		$this->assertSame(array_slice(self::TABLES, 3), $this->created);
	}
}
