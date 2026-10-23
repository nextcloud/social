<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261022000001;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RecommendationPreferencesMigrationTest extends TestCase {
	public function testAddsOnlyOwnerScopedPreferenceTablesAndIsIdempotent(): void {
		$schema = new FakeSchema();
		$migration = new Version1000Date20261022000001();
		$output = $this->createStub(IOutput::class);
		$migration->changeSchema($output, static fn (): FakeSchema => $schema, []);
		$shape = $schema->shape();

		$this->assertSame(['actor_key'], $shape['social_rec_profile']['primary']);
		$this->assertSame(['actor_key', 'topic_id'], $shape['social_rec_pref']['primary']);
		$this->assertArrayHasKey('generation', $shape['social_rec_profile']['columns']);
		$this->assertNull($migration->changeSchema($output, static fn (): FakeSchema => $schema, []));
	}
}
