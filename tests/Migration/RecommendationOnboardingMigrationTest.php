<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261022000001;
use OCA\Social\Migration\Version1000Date20261023000004;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class RecommendationOnboardingMigrationTest extends TestCase {
	public function testAddsDefaultsForExistingProfilesAndIsIdempotent(): void {
		$schema = new FakeSchema();
		$output = $this->createStub(IOutput::class);
		(new Version1000Date20261022000001())->changeSchema($output, static fn (): FakeSchema => $schema, []);
		$migration = new Version1000Date20261023000004();

		$migration->changeSchema($output, static fn (): FakeSchema => $schema, []);
		$profile = $schema->shape()['social_rec_profile']['columns'];
		$this->assertSame('balanced', $profile['content_balance']['options']['default']);
		$this->assertSame(0, $profile['onboarding_step']['options']['default']);
		$this->assertSame(0, $profile['onboarding_complete']['options']['default']);
		$this->assertNull($migration->changeSchema($output, static fn (): FakeSchema => $schema, []));
	}
}
