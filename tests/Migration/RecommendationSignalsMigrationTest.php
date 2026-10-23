<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261023000002;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RecommendationSignalsMigrationTest extends TestCase {
	public function testAddsBoundedOwnerScopedSignalAndExposureTablesIdempotently(): void {
		$schema = new FakeSchema();
		$migration = new Version1000Date20261023000002();
		$output = $this->createStub(IOutput::class);
		$migration->changeSchema($output, static fn (): FakeSchema => $schema, []);
		$shape = $schema->shape();

		$this->assertSame(['actor_key', 'dimension', 'entity_key'], $shape['social_rec_signal']['primary']);
		$this->assertArrayHasKey('fast_score', $shape['social_rec_signal']['columns']);
		$this->assertSame(['actor_key', 'event_id'], $shape['social_rec_event']['primary']);
		$this->assertSame(['actor_key', 'post_key', 'session_key'], $shape['social_rec_seen']['primary']);
		$this->assertNull($migration->changeSchema($output, static fn (): FakeSchema => $schema, []));
	}
}
