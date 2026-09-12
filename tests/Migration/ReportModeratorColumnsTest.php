<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Migration\Version1000Date20260911000013;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The step that gives a report somewhere to record who is handling it.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * outside this suite (see the class docblock); what is checked here is the
 * schema the step asks for, and that it asks for nothing twice — a migration
 * that re-adds an existing column fails the upgrade it is part of.
 */
class ReportModeratorColumnsTest extends TestCase {
	use RecordsSchemaChanges;

	/** @var array<string, array{string, array}> column => [type, options] */
	private array $added = [];

	private function schemaClosure(bool $hasTable = true, array $existing = []): Closure {
		$table = $this->recordTable('social_report', $existing);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('social_report')->willReturn($hasTable);
		$schema->method('getTable')->with('social_report')->willReturn($table);

		return static fn (): ISchemaWrapper => $schema;
	}

	private function applyStep(bool $hasTable = true, array $existing = []): void {
		(new Version1000Date20260911000013())
			->changeSchema($this->createMock(IOutput::class), $this->schemaClosure($hasTable, $existing), []);
		$this->harvestSchemaChanges();
	}

	public function testTheModeratorsAreBoundedUserIdsAndNotActorIds(): void {
		$this->applyStep();

		foreach (['assigned_to', 'action_taken_by'] as $column) {
			$this->assertArrayHasKey($column, $this->added);
			[$type, $options] = $this->added[$column];

			$this->assertSame(Types::STRING, $type);
			// a Nextcloud user id, which the server bounds at 64 characters —
			// an administrator moderates as a user of this server and need not
			// have a Social account at all
			$this->assertSame(64, $options['length']);
		}
	}

	public function testNothingIsAssignedUntilSomebodyTakesIt(): void {
		$this->applyStep();

		foreach (['assigned_to', 'action_taken_by', 'action_taken_at'] as $column) {
			// nullable, because a report is assigned to nobody far more often
			// than to somebody, and a report resolved before this step ran has
			// no moderator and no moment recorded
			$this->assertFalse($this->added[$column][1]['notnull']);
		}
	}

	public function testTheMomentOfTheDecisionIsADate(): void {
		$this->applyStep();

		$this->assertSame(Types::DATETIME, $this->added['action_taken_at'][0]);
	}

	public function testASecondRunAsksForNothing(): void {
		$this->applyStep(true, ['assigned_to', 'action_taken_by', 'action_taken_at']);

		$this->assertSame([], $this->added);
	}

	public function testAColumnAlreadyThereIsLeftAlone(): void {
		$this->applyStep(true, ['assigned_to']);

		$this->assertSame(['action_taken_by', 'action_taken_at'], array_keys($this->added));
	}

	public function testNothingIsAskedOfATableThatIsNotThere(): void {
		$this->applyStep(false);

		$this->assertSame([], $this->added);
	}
}
