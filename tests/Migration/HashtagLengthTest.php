<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Db\HashtagsRequest;
use OCA\Social\Migration\Version1000Date20260918000001;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The trends table has to hold a hashtag as long as the table it counts.
 *
 * `social_stream_tag.hashtag` is 127 characters and `social_hashtag.hashtag`
 * was 63, so a tag between the two lengths could be stored on a post and never
 * on its trend: the write failed outright, and since the failure was not
 * caught it ended the trends pass — leaving every hashtag after it in
 * iteration order unwritten, on that run and on every run after it.
 */
class HashtagLengthTest extends TestCase {
	use RecordsSchemaChanges;

	private function applyTo(?FakeTable $table): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => $table !== null && $name === 'social_hashtag'
		);
		$schema->method('getTable')->willReturnCallback(static fn (): FakeTable => $table);

		(new Version1000Date20260918000001())->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			[]
		);
	}

	private function table(?int $length): FakeTable {
		$table = $this->recordTable('social_hashtag');
		if ($length !== null) {
			$table->addColumn('hashtag', Types::STRING, ['notnull' => false, 'length' => $length]);
		}

		return $table;
	}

	public function testTheColumnIsWidenedToWhatAPostCanCarry(): void {
		$table = $this->table(63);

		$this->applyTo($table);

		$this->assertSame(
			HashtagsRequest::HASHTAG_MAX_LENGTH, $table->getColumn('hashtag')->getLength()
		);
	}

	public function testAColumnThatIsAlreadyWideEnoughIsLeftAlone(): void {
		$table = $this->table(HashtagsRequest::HASHTAG_MAX_LENGTH);

		$this->applyTo($table);

		$this->assertSame(
			HashtagsRequest::HASHTAG_MAX_LENGTH, $table->getColumn('hashtag')->getLength()
		);
	}

	public function testATableThisAppHasNotCreatedYetIsNotTouched(): void {
		$this->expectNotToPerformAssertions();

		$this->applyTo(null);
	}

	public function testTheWidthIsTheOneTheOtherTableStoresATagIn(): void {
		// both tables hold the same string; the migration that created them
		// gave them two different lengths
		$source = (string)file_get_contents(
			__DIR__ . '/../../lib/Migration/Version1000Date20221118000001.php'
		);
		$streamTags = substr($source, (int)strpos($source, 'createStreamTags'));
		$this->assertMatchesRegularExpression(
			"/'hashtag', Types::STRING,\s*\[\s*'notnull' => false,\s*'length' => "
			. HashtagsRequest::HASHTAG_MAX_LENGTH . '/',
			$streamTags,
			'social_stream_tag holds a hashtag of a different length than social_hashtag now does'
		);
	}
}
