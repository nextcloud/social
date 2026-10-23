<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261023000003;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RecommendationDiscoveryHealthMigrationTest extends TestCase {
	public function testAddsBoundedProviderHourAggregatesIdempotently(): void {
		$schema = new FakeSchema();
		$output = $this->createStub(IOutput::class);
		$migration = new Version1000Date20261023000003();
		$migration->changeSchema($output, static fn (): FakeSchema => $schema, []);
		$shape = $schema->shape();

		$this->assertSame(['provider', 'hour_bucket'], $shape['social_rec_discovery']['primary']);
		$this->assertArrayHasKey('rate_limited', $shape['social_rec_discovery']['columns']);
		$this->assertArrayHasKey('posts_stored', $shape['social_rec_discovery']['columns']);
		$this->assertNull($migration->changeSchema($output, static fn (): FakeSchema => $schema, []));
	}
}
