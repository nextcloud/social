<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Db\ListsRequestBuilder;
use OCA\Social\Migration\Version1000Date20260911000005;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The two tables a list lives in.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * outside this suite (see the class docblock of the step); what is checked
 * here is the schema the step asks for, that the indexes are the read paths
 * the routes actually take, and that a second run asks for nothing.
 */
class ListsTablesTest extends TestCase {
	use RecordsSchemaChanges;

	private const LISTS = 'social_list';
	private const MEMBERS = 'social_list_member';

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
		$step = new Version1000Date20260911000005();

		$schema = $step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure($existing), []);
		$this->harvestSchemaChangesByTable();

		return $schema;
	}

	public function testBothTablesAreTheOnesTheCodeReadsAndWrites(): void {
		$this->migrate();

		$this->assertSame(
			[ListsRequestBuilder::TABLE_LISTS, ListsRequestBuilder::TABLE_LIST_MEMBERS],
			$this->created
		);
	}

	public function testTheOwnerIsStoredTheShapeTheRestOfTheSchemaJoinsActorsBy(): void {
		$this->migrate();

		foreach ([self::LISTS, self::MEMBERS] as $table) {
			[$type, $options] = $this->added[$table]['actor_id_prim'];
			$this->assertSame(Types::STRING, $type, $table);
			$this->assertSame(32, $options['length'], $table . ': a prim is an md5');
			$this->assertTrue($options['notnull'], $table);

			// a prim is one-way, and the routes have to hand accounts back
			[$type, $options] = $this->added[$table]['actor_id'];
			$this->assertSame(Types::TEXT, $type, $table);
			$this->assertTrue($options['notnull'], $table);
		}
	}

	public function testTheTitleIsAsWideAsMastodonsAndTheEntityAgrees(): void {
		$this->migrate();

		[$type, $options] = $this->added[self::LISTS]['title'];
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(255, $options['length']);
		$this->assertTrue($options['notnull']);
		$this->assertSame(
			255,
			\OCA\Social\Db\ListsRequest::MAX_TITLE_LENGTH,
			'the length a title is cut to has to be the length the column holds,'
			. ' or a long title fails the insert on a strict MySQL'
		);
	}

	public function testThePolicyIsStoredByNameAndDefaultsToMastodonsDefault(): void {
		$this->migrate();

		[$type, $options] = $this->added[self::LISTS]['replies_policy'];
		$this->assertSame(Types::STRING, $type, 'the enum is stored as its name, not as an ordinal');
		$this->assertTrue($options['notnull']);
		$this->assertSame(
			\OCA\Social\Model\Client\MastodonList::DEFAULT_REPLIES_POLICY,
			$options['default'],
			'a row written without the column has to mean what a list created without it means'
		);
	}

	public function testAListIsFoundByItsOwner(): void {
		// GET /api/v1/lists is every list of one account, and every other list
		// route is "this id, and it must be mine"
		$this->migrate();

		$names = array_map(
			static fn (array $index): string => $index[1],
			array_filter($this->indexes[self::LISTS], static fn (array $i): bool => $i[0] === ['actor_id_prim'])
		);

		$this->assertSame(['social_list_a'], array_values($names));
	}

	public function testAMembershipIsUniquePerListAndAccount(): void {
		// which is what makes adding an account twice a no-op, and it is the
		// index the list timeline joins social_stream.attributed_to_prim on
		$this->migrate();

		$unique = array_values(
			array_filter($this->indexes[self::MEMBERS], static fn (array $i): bool => $i[2])
		);

		$this->assertCount(1, $unique);
		$this->assertSame(['list_id', 'actor_id_prim'], $unique[0][0]);
		$this->assertSame('social_lm_la', $unique[0][1]);
	}

	public function testAnAccountCanBeAskedWhichListsItIsIn(): void {
		// GET /api/v1/accounts/{id}/lists reads the membership table by member,
		// which the (list_id, actor_id_prim) index cannot answer: its leading
		// column is the list
		$this->migrate();

		$byMember = array_values(array_filter(
			$this->indexes[self::MEMBERS],
			static fn (array $i): bool => $i[0] === ['actor_id_prim'] && !$i[2]
		));

		$this->assertCount(1, $byMember);
		$this->assertSame('social_lm_a', $byMember[0][1]);
	}

	public function testBothTablesCarryAnAutoincrementKeyToPageOn(): void {
		$this->migrate();

		foreach ([self::LISTS, self::MEMBERS] as $table) {
			$this->assertSame(['id'], $this->primaryKeys[$table], $table);
			[$type, $options] = $this->added[$table]['id'];
			$this->assertSame(Types::BIGINT, $type, $table);
			$this->assertTrue($options['autoincrement'], $table);
		}
	}

	public function testAnUpgradeThatFailedBetweenTheTwoCreatesOnlyWhatIsMissing(): void {
		$schema = $this->migrate([self::LISTS]);

		$this->assertNotNull($schema);
		$this->assertSame([self::MEMBERS], $this->created);
	}

	public function testASecondRunCreatesNothing(): void {
		$schema = $this->migrate([self::LISTS, self::MEMBERS]);

		$this->assertNull($schema, 'a step that changes nothing returns null');
		$this->assertSame([], $this->created);
	}
}
