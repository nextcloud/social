<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261022000001;
use OCA\Social\Migration\Version1000Date20261023000001;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RecommendationFeedbackMigrationTest extends TestCase {
	public function testAddsFeedbackHistoryAndProfileVersionPortablyAndIdempotently(): void {
		$schema = new FakeSchema();
		$output = $this->createStub(IOutput::class);
		(new Version1000Date20261022000001())->changeSchema($output, static fn (): FakeSchema => $schema, []);
		$migration = new Version1000Date20261023000001();

		$migration->changeSchema($output, static fn (): FakeSchema => $schema, []);
		$shape = $schema->shape();

		$this->assertArrayHasKey('feedback_ver', $shape['social_rec_profile']['columns']);
		$this->assertSame(['feedback_id'], $shape['social_rec_feedback']['primary']);
		$this->assertArrayHasKey('revision', $shape['social_rec_feedback']['columns']);
		$this->assertCount(2, $shape['social_rec_feedback']['indexes']);
		$this->assertNull($migration->changeSchema($output, static fn (): FakeSchema => $schema, []));
	}
}
