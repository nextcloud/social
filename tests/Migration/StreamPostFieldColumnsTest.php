<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use Closure;
use OCA\Social\Migration\Version1000Date20260912000003;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The step that gives the five post fields columns of their own.
 *
 * What the DDL becomes on each platform is verified against the real DBAL
 * outside this suite; what is checked here is the schema the step asks for, and
 * that it asks for nothing twice — a migration that re-adds an existing column
 * fails the upgrade it is part of.
 */
class StreamPostFieldColumnsTest extends TestCase {
	use RecordsSchemaChanges;

	/** @var array<string, array{string, array}> column => [type, options] */
	private array $added = [];
	/** @var list<array{list<string>, ?string, bool}> */
	private array $indexes = [];

	private function schemaClosure(
		bool $hasTable = true,
		array $existingColumns = [],
		array $existingIndexes = [],
	): Closure {
		$table = $this->recordTable('social_stream', $existingColumns, $existingIndexes);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('social_stream')->willReturn($hasTable);
		$schema->method('getTable')->with('social_stream')->willReturn($table);

		return static fn (): ISchemaWrapper => $schema;
	}

	private function applyStep(
		bool $hasTable = true,
		array $existingColumns = [],
		array $existingIndexes = [],
	): ?ISchemaWrapper {
		$schema = (new Version1000Date20260912000003())->changeSchema(
			$this->createMock(IOutput::class),
			$this->schemaClosure($hasTable, $existingColumns, $existingIndexes),
			[]
		);
		$this->harvestSchemaChanges();

		return $schema;
	}

	public function testAllFiveFieldsGetAColumn(): void {
		$this->applyStep();

		$this->assertSame(
			['tags', 'language', 'updated', 'quote', 'quote_authorization'],
			array_keys($this->added)
		);
	}

	public function testTheTagArrayStaysJson(): void {
		$this->applyStep();

		// the `tag` array is heterogeneous — hashtags, mentions and emoji — and
		// its one queryable facet already has social_stream_tag. A column keeps
		// the re-export honest without a second source of truth for hashtags
		$this->assertSame(Types::TEXT, $this->added['tags'][0]);
	}

	public function testTheLanguageIsBoundedAndIndexed(): void {
		$this->applyStep();

		[$type, $options] = $this->added['language'];
		$this->assertSame(Types::STRING, $type);
		// Stream::normalizeLanguage() cannot emit more than `xxx-Xxxx-XXX`
		$this->assertSame(15, $options['length']);

		$this->assertSame([[['language'], 'social_s_lang', false]], $this->indexes);
	}

	public function testTheEditStampIsANullableDate(): void {
		$this->applyStep();

		[$type, $options] = $this->added['updated'];
		$this->assertSame(Types::DATETIME, $type);
		// a post that was never edited has no edit time, which is not the same
		// fact as one edited at the epoch
		$this->assertFalse($options['notnull']);
		$this->assertArrayNotHasKey('default', $options);
	}

	public function testTheTwoIdsAreStoredLikeEveryOtherActivityPubId(): void {
		$this->applyStep();

		foreach (['quote', 'quote_authorization'] as $column) {
			$this->assertSame(Types::TEXT, $this->added[$column][0]);
		}
	}

	public function testNeitherIdGetsAPrimCompanion(): void {
		$this->applyStep();

		// `*_prim` exists so an existing lookup can be an indexed equality.
		// Nothing looks a post up by what it quotes, and quote_authorization is
		// only ever a URI a peer dereferences — an md5 column and its index on
		// the largest table in the app would cost every insert for no read
		foreach (array_keys($this->added) as $column) {
			$this->assertStringEndsNotWith('_prim', $column);
		}
	}

	public function testASecondRunAsksForNothing(): void {
		$schema = $this->applyStep(
			true,
			['tags', 'language', 'updated', 'quote', 'quote_authorization'],
			['social_s_lang']
		);

		$this->assertSame([], $this->added);
		$this->assertSame([], $this->indexes);
		$this->assertNull($schema);
	}

	public function testAColumnAlreadyThereIsLeftAlone(): void {
		$this->applyStep(true, ['language'], ['social_s_lang']);

		$this->assertSame(['tags', 'updated', 'quote', 'quote_authorization'], array_keys($this->added));
		$this->assertSame([], $this->indexes);
	}

	public function testTheIndexIsAddedToAColumnThatIsAlreadyThere(): void {
		// an instance that got the column from a half-applied run still needs
		// the index, which is the only thing that makes a language filter work
		$this->applyStep(true, ['language']);

		$this->assertSame([[['language'], 'social_s_lang', false]], $this->indexes);
	}

	public function testNothingIsAskedOfATableThatIsNotThere(): void {
		$schema = $this->applyStep(false);

		$this->assertSame([], $this->added);
		$this->assertNull($schema);
	}
}
