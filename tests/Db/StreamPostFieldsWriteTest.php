<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\CoreRequestBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The five post fields are only worth a column if every write path fills it.
 *
 * Reading them back out of `social_stream` is the whole point of
 * `Version1000Date20260912000003`, and a column an insert leaves empty parses as
 * "this post has no language" — indistinguishable from the honest answer, and
 * invisible until a language filter reports the post as having none. An edit
 * that does not write it is worse: the column then holds what the post used to
 * say while the wire object beside it says something else.
 *
 * Neither write path can be exercised here, since the unit suite has no
 * database. So the source is read instead, the way StreamSensitiveColumnTest
 * reads it.
 */
class StreamPostFieldsWriteTest extends TestCase {
	private const SOURCE = __DIR__ . '/../../lib/Db/StreamRequest.php';

	private const COLUMNS = ['tags', 'language', 'updated', 'quote', 'quote_authorization'];

	public function testTheStreamTableCarriesAllFiveColumns(): void {
		$tables = (new \ReflectionClass(CoreRequestBuilder::class))
			->getStaticPropertyValue('tables');

		foreach (self::COLUMNS as $column) {
			$this->assertContains(
				$column,
				$tables[CoreRequestBuilder::TABLE_STREAM],
				'a column no query selects parses as its default, i.e. as the field being absent'
			);
		}
	}

	public function testOneHelperServesBothWritePaths(): void {
		$source = (string)file_get_contents(self::SOURCE);

		$this->assertMatchesRegularExpression(
			'/setPostFields\(\$qb, \$stream, true\)/',
			$source,
			'saveStream() does not write the five fields, so a new post has them only in its JSON'
		);
		$this->assertMatchesRegularExpression(
			'/setPostFields\(\$qb, \$stream, false\)/',
			$source,
			'update() does not write the five fields, so an edit leaves the columns saying what'
			. ' the post used to say'
		);

		foreach (self::COLUMNS as $column) {
			$this->assertMatchesRegularExpression(
				"/'" . $column . "' => \[/",
				$source,
				'setPostFields() does not write ' . $column
			);
		}
	}

	public function testTheEditTimeIsConvertedBeforeItIsBound(): void {
		$source = (string)file_get_contents(self::SOURCE);

		// Doctrine renders a DateTime in whatever zone the object carries, so a
		// remote `updated` of 10:00+02:00 bound as it arrives stores the right
		// text for the wrong instant
		$this->assertMatchesRegularExpression(
			"/setTimezone\(new DateTimeZone\('UTC'\)\)/",
			$source,
			'updatedAsDate() no longer normalises to UTC'
		);
	}
}
