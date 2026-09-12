<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20260911000004;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The table a followed hashtag lives in.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * outside this suite (see the class docblock of the step); what is checked
 * here is the schema the step asks for, and that a second run asks for
 * nothing.
 */
class FollowedTagsTableTest extends TestCase {
	use RecordsSchemaChanges;

	/** @var array<string, array{string, array}> column => [type, options] */
	private array $added = [];
	/** @var array<int, array{string[], string, bool}> [columns, name, unique] */
	private array $indexes = [];
	/** @var string[] */
	private array $primaryKey = [];
	private ?string $created = null;

	private function schemaClosure(bool $hasTable = false): Closure {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn($hasTable);
		$schema->method('createTable')
			->willReturnCallback(function (string $name) {
				$this->created = $name;

				return $this->recordTable($name);
			});

		return static fn (): ISchemaWrapper => $schema;
	}

	private function migrate(bool $hasTable = false): ?ISchemaWrapper {
		$step = new Version1000Date20260911000004();

		$schema = $step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure($hasTable), []);
		$this->harvestSchemaChanges();

		return $schema;
	}

	public function testTheTableIsTheOneTheCodeReadsAndWrites(): void {
		$this->migrate();

		$this->assertSame(CoreRequestBuilder::TABLE_FOLLOWED_TAGS, $this->created);
		$this->assertSame(
			CoreRequestBuilder::$tables[CoreRequestBuilder::TABLE_FOLLOWED_TAGS],
			array_keys($this->added),
			'every column the step creates is declared in CoreRequestBuilder, and nothing else'
		);
	}

	public function testTheOwnerAndTheTagAreTheShapeTheRestOfTheSchemaUses(): void {
		$this->migrate();

		[$type, $options] = $this->added['actor_id_prim'];
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(32, $options['length'], 'a prim is an md5');
		$this->assertTrue($options['notnull']);

		[$type, $options] = $this->added['hashtag'];
		$this->assertSame(Types::STRING, $type);
		$this->assertSame(
			127,
			$options['length'],
			'the same width social_stream_tag.hashtag has, or a followable tag could'
			. ' be one no post can carry'
		);
		$this->assertTrue($options['notnull']);
	}

	public function testThePairIsUniqueAndIsTheIndexTheTimelineJoinReads(): void {
		$this->migrate();

		$unique = array_values(array_filter($this->indexes, static fn (array $i): bool => $i[2]));
		$this->assertCount(1, $unique);
		$this->assertSame(['actor_id_prim', 'hashtag'], $unique[0][0]);
		$this->assertSame('social_ft_ah', $unique[0][1]);
	}

	public function testTheRowsCarryAnAutoincrementKeyToPageOn(): void {
		$this->migrate();

		$this->assertSame(['id'], $this->primaryKey);
		[$type, $options] = $this->added['id'];
		$this->assertSame(Types::BIGINT, $type);
		$this->assertTrue($options['autoincrement']);
	}

	public function testASecondRunCreatesNothing(): void {
		$schema = $this->migrate(true);

		$this->assertNull($schema, 'a step that changes nothing returns null');
		$this->assertSame([], $this->added);
		$this->assertNull($this->created);
	}
}
