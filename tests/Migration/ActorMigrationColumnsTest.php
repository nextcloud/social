<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Migration\Version1000Date20260911000002;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The step that gives a local actor somewhere to keep its directory flags and
 * its migration state.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * outside this suite (see the class docblock); what is checked here is the
 * schema the step asks for, and that it asks for nothing twice.
 */
class ActorMigrationColumnsTest extends TestCase {
	use RecordsSchemaChanges;

	/** @var array<string, array{string, array}> column => [type, options] */
	private array $added = [];

	private function schemaClosure(bool $hasTable = true, array $existing = []): Closure {
		$table = $this->recordTable('social_actor', $existing);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('social_actor')->willReturn($hasTable);
		$schema->method('getTable')->with('social_actor')->willReturn($table);

		return static fn (): ISchemaWrapper => $schema;
	}

	public function testTheFlagsAreOptInSmallints(): void {
		$step = new Version1000Date20260911000002();
		$step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure(), []);
		$this->harvestSchemaChanges();

		foreach (['discoverable', 'indexable'] as $flag) {
			$this->assertArrayHasKey($flag, $this->added);
			[$type, $options] = $this->added[$flag];
			$this->assertSame(Types::SMALLINT, $type);
			$this->assertTrue($options['notnull']);
			$this->assertSame(0, $options['default'], 'opt-in, like Mastodon: existing actors stay hidden');
		}
	}

	public function testTheMigrationColumnsAreNullableText(): void {
		$step = new Version1000Date20260911000002();
		$step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure(), []);
		$this->harvestSchemaChanges();

		foreach (['also_known_as', 'moved_to'] as $column) {
			$this->assertArrayHasKey($column, $this->added);
			[$type, $options] = $this->added[$column];
			// an actor id is a URL of no bounded length, and a TEXT column may
			// not carry a default on MySQL
			$this->assertSame(Types::TEXT, $type);
			$this->assertFalse($options['notnull']);
			$this->assertArrayNotHasKey('default', $options);
		}
	}

	public function testColumnsThatExistAreLeftAlone(): void {
		$step = new Version1000Date20260911000002();
		$step->changeSchema(
			$this->createMock(IOutput::class),
			$this->schemaClosure(true, ['discoverable', 'indexable', 'also_known_as', 'moved_to']),
			[]
		);
		$this->harvestSchemaChanges();

		$this->assertSame([], $this->added);
	}

	public function testAMissingTableIsNotAnError(): void {
		$step = new Version1000Date20260911000002();
		$schema = $step->changeSchema($this->createMock(IOutput::class), $this->schemaClosure(false), []);

		$this->assertInstanceOf(ISchemaWrapper::class, $schema);
		$this->assertSame([], $this->added);
	}
}
