<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Db\AccountNotesRequestBuilder;
use OCA\Social\Db\DomainBlocksRequestBuilder;
use OCA\Social\Db\MuteExpiryRequestBuilder;
use OCA\Social\Migration\Version1000Date20260911000008;
use OCA\Social\Service\AccountRelationService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The three tables the per-account decisions live in.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * outside this suite (see the class docblock of the step); what is checked here
 * is the schema the step asks for, that each index is a read path a route
 * actually takes, and that a second run asks for nothing.
 */
class RelationTablesTest extends TestCase {
	use RecordsSchemaChanges;

	private const DOMAIN_BLOCKS = 'social_domain_block';
	private const NOTES = 'social_account_note';
	private const MUTE_EXPIRY = 'social_mute_expiry';

	/** @var array<string, array<string, array{string, array}>> table => column => [type, options] */
	private array $added = [];
	/** @var array<string, array<int, array{string[], string, bool}>> table => [columns, name, unique] */
	private array $indexes = [];
	/** @var array<string, string[]> table => primary key columns */
	private array $primaryKeys = [];
	/** @var string[] the tables the step created, in order */
	private array $created = [];

	/** @param string[] $existing tables that are already there */
	private function schemaClosure(array $existing = []): Closure {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')
			->willReturnCallback(static fn (string $name): bool => in_array($name, $existing, true));
		$schema->method('createTable')
			->willReturnCallback(function (string $name) {
				$this->created[] = $name;
				$this->added[$name] = [];
				$this->indexes[$name] = [];
				$this->primaryKeys[$name] = [];

				return $this->recordTable($name);
			});

		return static fn (): ISchemaWrapper => $schema;
	}

	/** @param string[] $existing */
	private function migrate(array $existing = []): ?ISchemaWrapper {
		$step = new Version1000Date20260911000008();

		$schema = $step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure($existing), []);
		$this->harvestSchemaChangesByTable();

		return $schema;
	}

	public function testTheTablesAreTheOnesTheCodeReadsAndWrites(): void {
		$this->migrate();

		$this->assertSame(
			[
				DomainBlocksRequestBuilder::TABLE_DOMAIN_BLOCKS,
				AccountNotesRequestBuilder::TABLE_ACCOUNT_NOTES,
				MuteExpiryRequestBuilder::TABLE_MUTE_EXPIRY,
			],
			$this->created
		);
	}

	public function testEveryTableKeysItsOwnerTheShapeTheRestOfTheSchemaJoinsActorsBy(): void {
		$this->migrate();

		foreach ([self::DOMAIN_BLOCKS, self::NOTES, self::MUTE_EXPIRY] as $table) {
			[$type, $options] = $this->added[$table]['actor_id_prim'];
			$this->assertSame(Types::STRING, $type, $table);
			$this->assertSame(32, $options['length'], $table . ': a prim is an md5');
			$this->assertTrue($options['notnull'], $table);
		}
	}

	public function testABlockedInstanceIsStoredOncePerAccount(): void {
		// which is what makes blocking twice a no-op, and it is the index every
		// timeline read probes
		$this->migrate();

		$this->assertSame(
			[[['actor_id_prim', 'domain'], 'social_dblk_ad', true]],
			$this->indexes[self::DOMAIN_BLOCKS]
		);
	}

	public function testTheDomainColumnHoldsAnyHostThatCanBeTyped(): void {
		$this->migrate();

		[$type, $options] = $this->added[self::DOMAIN_BLOCKS]['domain'];
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(255, $options['length'], 'the longest a host name can be');
		$this->assertTrue($options['notnull']);
	}

	public function testANoteSaysWhoItIsAboutAndNotOnlyItsHash(): void {
		// a prim is one-way: without the id beside it the row cannot say whose
		// note it is, and this is the one table here worth exporting
		$this->migrate();

		[$type] = $this->added[self::NOTES]['object_id'];
		$this->assertSame(Types::TEXT, $type);

		[$type, $options] = $this->added[self::NOTES]['object_id_prim'];
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length']);
	}

	public function testThereIsOneNotePerPairAndWritingASecondReplacesIt(): void {
		$this->migrate();

		$this->assertSame(
			[[['actor_id_prim', 'object_id_prim'], 'social_anote_ao', true]],
			$this->indexes[self::NOTES]
		);
	}

	public function testTheNoteColumnHoldsWhatMastodonAllows(): void {
		// 2000 characters is no VARCHAR anything could index, and nothing ever
		// searches by a note
		$this->migrate();

		[$type] = $this->added[self::NOTES]['note'];
		$this->assertSame(Types::TEXT, $type);
		$this->assertSame(2000, AccountRelationService::MAX_NOTE);
	}

	public function testAnExpiryRowThatExpiresAtNothingCannotBeWritten(): void {
		// no expiry is no row; a nullable column would make "permanent" and
		// "expired in 1970" the same write
		$this->migrate();

		[$type, $options] = $this->added[self::MUTE_EXPIRY]['expires_at'];
		$this->assertSame(Types::DATETIME, $type);
		$this->assertTrue($options['notnull']);
	}

	public function testThereIsOneExpiryPerMute(): void {
		// the timeline join reads it by (viewer, muted account), and re-muting
		// with another duration has to move the expiry rather than add one
		$this->migrate();

		$this->assertSame(
			[[['actor_id_prim', 'object_id_prim'], 'social_mexp_ao', true]],
			$this->indexes[self::MUTE_EXPIRY]
		);
	}

	public function testEveryTableCarriesAnAutoincrementKey(): void {
		$this->migrate();

		foreach ([self::DOMAIN_BLOCKS, self::NOTES, self::MUTE_EXPIRY] as $table) {
			$this->assertSame(['id'], $this->primaryKeys[$table], $table);
			[$type, $options] = $this->added[$table]['id'];
			$this->assertSame(Types::BIGINT, $type, $table);
			$this->assertTrue($options['autoincrement'], $table);
		}
	}

	public function testAnUpgradeThatFailedPartWayThroughCreatesOnlyWhatIsMissing(): void {
		$schema = $this->migrate([self::DOMAIN_BLOCKS, self::NOTES]);

		$this->assertNotNull($schema);
		$this->assertSame([self::MUTE_EXPIRY], $this->created);
	}

	public function testASecondRunCreatesNothing(): void {
		$schema = $this->migrate([self::DOMAIN_BLOCKS, self::NOTES, self::MUTE_EXPIRY]);

		$this->assertNull($schema, 'a step that changes nothing returns null');
		$this->assertSame([], $this->created);
	}
}
