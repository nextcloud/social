<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Db\AnnouncementsRequestBuilder;
use OCA\Social\Migration\Version1000Date20260911000011;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The two tables an announcement lives in.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * outside this suite (see the class docblock of the step); what is checked
 * here is the schema the step asks for, that the columns are the ones the
 * entity is built out of, that the index is the read the client route takes,
 * and that a second run asks for nothing.
 */
class AnnouncementTablesTest extends TestCase {
	use RecordsSchemaChanges;

	private const ANNOUNCEMENTS = 'social_announcement';
	private const READS = 'social_announce_read';

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
		$step = new Version1000Date20260911000011();

		$schema = $step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure($existing), []);
		$this->harvestSchemaChangesByTable();

		return $schema;
	}

	public function testBothTablesAreTheOnesTheCodeReadsAndWrites(): void {
		$this->migrate();

		$this->assertSame(
			[
				AnnouncementsRequestBuilder::TABLE_ANNOUNCEMENTS,
				AnnouncementsRequestBuilder::TABLE_ANNOUNCEMENT_READS,
			],
			$this->created
		);
	}

	public function testTheNoticeIsStoredWhole(): void {
		// a notice about an outage is paragraphs, not a title: a VARCHAR would
		// cut one off on MySQL and fail the insert on a strict one
		$this->migrate();

		[$type, $options] = $this->added[self::ANNOUNCEMENTS]['content'];

		$this->assertSame(Types::TEXT, $type);
		$this->assertTrue($options['notnull']);
	}

	public function testBothBoundsOfTheWindowAreNullableDates(): void {
		// NULL is "no bound", which is what the read compares against: an
		// announcement with no window has to match either way round
		$this->migrate();

		foreach (['starts_at', 'ends_at'] as $column) {
			[$type, $options] = $this->added[self::ANNOUNCEMENTS][$column];

			$this->assertSame(Types::DATETIME, $type, $column);
			$this->assertFalse($options['notnull'], $column);
		}
	}

	public function testAnAnnouncementIsDatedAtBothEndsOfItsOwnLife(): void {
		// creation is the entity's published_at and last_update its updated_at,
		// which Mastodon documents as non-nullable
		$this->migrate();

		foreach (['creation', 'last_update'] as $column) {
			$this->assertSame(Types::DATETIME, $this->added[self::ANNOUNCEMENTS][$column][0], $column);
		}
	}

	public function testWholeDaysIsAFlagThatDefaultsToOff(): void {
		$this->migrate();

		[$type, $options] = $this->added[self::ANNOUNCEMENTS]['all_day'];

		$this->assertSame(Types::SMALLINT, $type);
		$this->assertTrue($options['notnull']);
		$this->assertSame(0, $options['default'], 'a row written without it is not a whole-day window');
	}

	public function testAnAnnouncementNeedsNoIndexBeyondItsKey(): void {
		// every read of this table is its whole active set, and it holds a
		// handful of rows: an index on a date would be read past on all of them
		$this->migrate();

		$this->assertSame([], $this->indexes[self::ANNOUNCEMENTS]);
		$this->assertSame(['id'], $this->primaryKeys[self::ANNOUNCEMENTS]);
	}

	public function testADismissalIsUniquePerAccountAndAnnouncement(): void {
		// which is what makes dismissing twice a no-op, and it is the index the
		// client read probes: one account's dismissals among a page of ids, so
		// the account is the leading column
		$this->migrate();

		$unique = array_values(
			array_filter($this->indexes[self::READS], static fn (array $i): bool => $i[2])
		);

		$this->assertCount(1, $unique);
		$this->assertSame(['actor_id_prim', 'announcement_id'], $unique[0][0]);
		$this->assertSame('social_annread_aa', $unique[0][1]);
	}

	public function testTheAccountIsStoredTheShapeTheRestOfTheSchemaJoinsActorsBy(): void {
		$this->migrate();

		[$type, $options] = $this->added[self::READS]['actor_id_prim'];

		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length'], 'a prim is an md5');
		$this->assertTrue($options['notnull']);
	}

	public function testADismissalPointsAtAnAnnouncementByItsKey(): void {
		$this->migrate();

		[$type, $options] = $this->added[self::READS]['announcement_id'];

		$this->assertSame(Types::BIGINT, $type);
		$this->assertTrue($options['notnull']);
		$this->assertTrue($options['unsigned']);
	}

	public function testBothTablesCarryAnAutoincrementKey(): void {
		$this->migrate();

		foreach ([self::ANNOUNCEMENTS, self::READS] as $table) {
			$this->assertSame(['id'], $this->primaryKeys[$table], $table);
			[$type, $options] = $this->added[$table]['id'];
			$this->assertSame(Types::BIGINT, $type, $table);
			$this->assertTrue($options['autoincrement'], $table);
		}
	}

	public function testAnUpgradeThatFailedBetweenTheTwoCreatesOnlyWhatIsMissing(): void {
		$schema = $this->migrate([self::ANNOUNCEMENTS]);

		$this->assertNotNull($schema);
		$this->assertSame([self::READS], $this->created);
	}

	public function testASecondRunCreatesNothing(): void {
		$schema = $this->migrate([self::ANNOUNCEMENTS, self::READS]);

		$this->assertNull($schema, 'a step that changes nothing returns null');
		$this->assertSame([], $this->created);
	}
}
