<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261015000100;
use OCA\Social\Migration\Version1000Date20261016000030;
use OCP\DB\Types;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * `social_starter_pack.member_count`: a count beside the member list, so the
 * index of packs reads no list. The count of the packs already stored is run
 * against a real database in `Integration\Migration\StarterPackMemberCountBackfillTest`.
 */
class StarterPackMemberCountColumnTest extends TestCase {
	private function schema(): FakeSchema {
		$schema = MigrationReplay::run([Version1000Date20221118000002::class], [IAppConfig::class => $this->createStub(IAppConfig::class)]);
		MigrationReplay::run([Version1000Date20261015000100::class, Version1000Date20261016000030::class], [], $schema);

		return $schema;
	}

	public function testTheColumnIsACountThatStartsAtNothing(): void {
		[$type, $options] = $this->schema()->getTable('social_starter_pack')->addedColumn('member_count');

		$this->assertSame(Types::INTEGER, $type);
		$this->assertTrue($options['notnull']);
		$this->assertSame(0, $options['default']);
	}

	public function testRunTwiceItAsksForNothingTheSecondTime(): void {
		$schema = $this->schema();
		$once = $schema->shape();

		MigrationReplay::run([Version1000Date20261016000030::class], [], $schema);

		$this->assertSame($once, $schema->shape());
	}
}
