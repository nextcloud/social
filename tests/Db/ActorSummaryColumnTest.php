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
 * `social_actor` has had a `summary` column and `create()` has always written
 * it, but nothing ever changed it afterwards: the only `UPDATE` that touched
 * it, `ActorsRequest::update()`, has no caller, so a local account federated
 * with an empty bio for its whole life. The write path cannot be exercised
 * without a database, so the source is read, the way ActorFlagColumnsTest
 * reads it.
 */
class ActorSummaryColumnTest extends TestCase {
	private const REQUEST = __DIR__ . '/../../lib/Db/ActorsRequest.php';
	private const BUILDER = __DIR__ . '/../../lib/Db/ActorsRequestBuilder.php';

	public function testTheActorTableDeclaresTheColumn(): void {
		$tables = (new \ReflectionClass(CoreRequestBuilder::class))
			->getStaticPropertyValue('tables');

		$this->assertContains('summary', $tables[CoreRequestBuilder::TABLE_ACTORS]);
	}

	public function testTheActorSelectReadsTheColumn(): void {
		$this->assertStringContainsString(
			"'a.summary'",
			(string)file_get_contents(self::BUILDER),
			'a column no query selects parses as its default, i.e. never set'
		);
	}

	public function testABioHasAWritePathOfItsOwn(): void {
		$source = (string)file_get_contents(self::REQUEST);

		$this->assertMatchesRegularExpression(
			'/function updateSummary\(Person \$actor\): void \{\s*\$qb = \$this->getActorsUpdateSql\(\);'
			. "\s*\\\$qb->set\('summary'/",
			$source,
			'nothing updates the bio of an existing actor'
		);
	}

	public function testTheBioIsWrittenAsStoredAndNeverReEncoded(): void {
		$source = (string)file_get_contents(self::REQUEST);

		$this->assertStringContainsString(
			"\$qb->set('summary', \$qb->createNamedParameter(\$actor->getSummary()))",
			$source,
			'the bio is stored as the plain text it is; the rendering to HTML belongs to the export'
		);
	}
}
