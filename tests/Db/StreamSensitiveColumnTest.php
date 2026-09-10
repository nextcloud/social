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
 * `sensitive` is the one client-visible flag that had nowhere to live: the
 * model carried it, both wire formats emitted it, and no column stored it, so
 * every re-read of a post reported the media as safe to show unasked. Reading
 * it back is only worth anything if both write paths put it there — and neither
 * can be exercised here, since the unit suite has no database. So the source is
 * read instead, the way StreamRequestBuilderTest reads it.
 */
class StreamSensitiveColumnTest extends TestCase {
	private const SOURCE = __DIR__ . '/../../lib/Db/StreamRequest.php';

	public function testTheStreamTableCarriesTheSensitiveColumn(): void {
		$tables = (new \ReflectionClass(CoreRequestBuilder::class))
			->getStaticPropertyValue('tables');

		$this->assertContains(
			'sensitive',
			$tables[CoreRequestBuilder::TABLE_STREAM],
			'a column no query selects parses as its default, i.e. never sensitive'
		);
	}

	public function testBothWritePathsStoreIt(): void {
		$source = (string)file_get_contents(self::SOURCE);

		$this->assertMatchesRegularExpression(
			"/setValue\(\s*'sensitive'/",
			$source,
			'saveStream() does not write the flag, so a new post loses it'
		);
		$this->assertMatchesRegularExpression(
			"/->set\(\s*'sensitive'/",
			$source,
			'update() does not write the flag, so editing a post cannot change it'
		);
	}
}
