<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20261004000002;
use OCA\Social\Migration\Version1000Date20261008000001;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tables added after the squash: the Files comment bridge
 * (`social_file_post`, `social_file_comment`) and the import jobs
 * (`social_import`). Nothing else creates them, so each step is the only
 * place an installation gets them from. Pinned: they hold exactly the
 * columns the request builders select, carry the indexes the reads take,
 * and a step run against a schema that already has its tables asks for
 * nothing.
 */
class FileBridgeAndImportTablesTest extends TestCase {
	/** @return array<string, array{class-string, string}> */
	public static function tablesAndSteps(): array {
		return [
			'file posts' => [Version1000Date20261008000001::class, CoreRequestBuilder::TABLE_FILE_POSTS],
			'file comments' => [Version1000Date20261008000001::class, CoreRequestBuilder::TABLE_FILE_COMMENTS],
			'imports' => [Version1000Date20261004000002::class, CoreRequestBuilder::TABLE_IMPORTS],
		];
	}

	/** @param class-string $step */
	#[DataProvider('tablesAndSteps')]
	public function testTheTableHoldsExactlyTheColumnsItsRequestsRead(string $step, string $table): void {
		$columns = array_keys(MigrationReplay::run([$step])->shape()[$table]['columns']);
		$declared = CoreRequestBuilder::$tables[$table];
		sort($declared);

		$this->assertSame($declared, $columns);
		$this->assertSame(['id'], MigrationReplay::run([$step])->shape()[$table]['primary']);
	}

	/** @param class-string $step */
	#[DataProvider('tablesAndSteps')]
	public function testRunAgainstItsOwnTablesTheStepAsksForNothing(string $step, string $table): void {
		$schema = MigrationReplay::run([$step]);
		$once = $schema->shape();

		$changed = (new $step())->changeSchema($this->createStub(IOutput::class), static fn (): FakeSchema => $schema, []);

		$this->assertNull($changed);
		$this->assertSame($once, $schema->shape());
	}

	/** An upgrade interrupted between the two tables finishes the second on the next run. */
	public function testAFileBridgeTableMissingAloneIsCreatedAlone(): void {
		$schema = MigrationReplay::run([Version1000Date20261008000001::class]);
		$posts = $schema->shape()[CoreRequestBuilder::TABLE_FILE_POSTS];
		$schema->dropTable(CoreRequestBuilder::TABLE_FILE_COMMENTS);

		$changed = (new Version1000Date20261008000001())
			->changeSchema($this->createStub(IOutput::class), static fn (): FakeSchema => $schema, []);

		$this->assertSame($schema, $changed);
		$this->assertTrue($schema->hasTable(CoreRequestBuilder::TABLE_FILE_COMMENTS));
		$this->assertSame($posts, $schema->shape()[CoreRequestBuilder::TABLE_FILE_POSTS], 'the other is left as it was');
	}

	/**
	 * A reply mirrored into a file's comments once per file, whichever of the
	 * two background paths gets there first: the unique index is what makes
	 * the second insert a no-op.
	 */
	public function testACommentMirrorIsUniquePerReplyAndFile(): void {
		$indexes = MigrationReplay::run([Version1000Date20261008000001::class])
			->shape()[CoreRequestBuilder::TABLE_FILE_COMMENTS]['indexes'];

		$this->assertContains(
			['columns' => ['reply_id_prim', 'file_id'], 'name' => 'social_fcom_reply', 'unique' => true],
			$indexes
		);
	}

	public function testAnImportJobStartsQueuedAndIsListedPerUserInOrder(): void {
		$table = MigrationReplay::run([Version1000Date20261004000002::class])->shape()[CoreRequestBuilder::TABLE_IMPORTS];

		$this->assertSame('queued', $table['columns']['status']['options']['default']);
		$this->assertSame(Types::TEXT, $table['columns']['report']['type'], 'a report of every line that failed');
		$this->assertContains(
			['columns' => ['user_id', 'id'], 'name' => 'social_imp_ui', 'unique' => false],
			$table['indexes']
		);
	}
}
