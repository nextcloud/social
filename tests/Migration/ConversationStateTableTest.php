<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Db\ConversationsRequestBuilder;
use OCA\Social\Migration\Version1000Date20260911000007;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The one table a conversation needs.
 *
 * The conversations themselves are derived from the thread a message hangs in
 * and are stored nowhere; what has a row is what an account has *done* with a
 * thread. What the DDL becomes on each platform is verified against the real
 * DBAL outside this suite (see the class docblock of the step); what is
 * checked here is the schema the step asks for, and that a second run asks for
 * nothing.
 */
class ConversationStateTableTest extends TestCase {
	use RecordsSchemaChanges;

	private const TABLE = 'social_convo_state';

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
		$step = new Version1000Date20260911000007();

		$schema = $step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure($existing), []);
		$this->harvestSchemaChangesByTable();

		return $schema;
	}

	public function testTheTableIsTheOneTheCodeReadsAndWrites(): void {
		$this->migrate();

		$this->assertSame(
			[ConversationsRequestBuilder::TABLE_CONVERSATION_STATE], $this->created
		);
	}

	public function testBothIdsAreStoredTheShapeTheRestOfTheSchemaJoinsBy(): void {
		$this->migrate();

		foreach (['actor_id' => 'actor_id_prim', 'root_id' => 'root_id_prim'] as $id => $prim) {
			[$type, $options] = $this->added[self::TABLE][$prim];
			$this->assertSame(Types::STRING, $type, $prim);
			$this->assertSame(32, $options['length'], $prim . ': a prim is an md5');
			$this->assertTrue($options['notnull'], $prim);

			// a prim is one-way, and the writer needs the real id to store a row
			[$type, $options] = $this->added[self::TABLE][$id];
			$this->assertSame(Types::TEXT, $type, $id);
			$this->assertTrue($options['notnull'], $id);
		}
	}

	public function testBothMarkersAreAMessageAndNotAFlag(): void {
		// a flag would be cleared on every incoming direct message — a write on
		// the delivery path — and could not tell "read" from "read up to here"
		$this->migrate();

		foreach (['read_nid', 'hidden_nid'] as $marker) {
			[$type, $options] = $this->added[self::TABLE][$marker];
			$this->assertSame(Types::BIGINT, $type, $marker);
			$this->assertTrue($options['notnull'], $marker);
			$this->assertSame(
				0, $options['default'],
				$marker . ': a thread with no row has been neither read nor dismissed,'
				. ' which is what an instance upgrading into this table starts with'
			);
		}
	}

	public function testAnAccountHasOneStatePerThread(): void {
		// two rows for one account and one thread would mean one of them
		// silently deciding what the user has read; the uniqueness is also what
		// makes the marker write safe to retry
		$this->migrate();

		$unique = array_values(
			array_filter($this->indexes[self::TABLE], static fn (array $i): bool => $i[2])
		);

		$this->assertCount(1, $unique);
		$this->assertSame(['actor_id_prim', 'root_id_prim'], $unique[0][0]);
		$this->assertSame('social_convo_ar', $unique[0][1]);
	}

	public function testTheOnlyIndexIsTheOneReadPath(): void {
		// its leading column also answers "every state of one account", which
		// is what deleting an account needs, so there is no second index
		$this->migrate();

		$this->assertCount(1, $this->indexes[self::TABLE]);
	}

	public function testTheTableCarriesAnAutoincrementKey(): void {
		$this->migrate();

		$this->assertSame(['id'], $this->primaryKeys[self::TABLE]);
		[$type, $options] = $this->added[self::TABLE]['id'];
		$this->assertSame(Types::BIGINT, $type);
		$this->assertTrue($options['autoincrement']);
	}

	public function testASecondRunCreatesNothing(): void {
		$schema = $this->migrate([self::TABLE]);

		$this->assertNull($schema, 'a step that changes nothing returns null');
		$this->assertSame([], $this->created);
	}
}
