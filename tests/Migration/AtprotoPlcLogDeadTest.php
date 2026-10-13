<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20261008000010;
use OCA\Social\Migration\Version1000Date20261013100300;
use PHPUnit\Framework\TestCase;

/**
 * The PLC log remembers what the directory refused or nullified: when,
 * and what it said.
 */
class AtprotoPlcLogDeadTest extends TestCase {
	public function testTheLogGetsADeathDateAndAReasonOnce(): void {
		$schema = MigrationReplay::run([Version1000Date20261008000010::class, Version1000Date20261013100300::class]);
		$columns = $schema->shape()['social_atproto_plc_log']['columns'];

		$this->assertSame(['cid', 'confirmed', 'creation', 'dead', 'did', 'id', 'operation', 'reason', 'sent'], array_keys($columns));
		$this->assertSame(['type' => 'datetime', 'options' => ['notnull' => false]], $columns['dead'], 'null until the directory refuses');
		$this->assertSame(['type' => 'string', 'options' => ['default' => '', 'length' => 255, 'notnull' => true]], $columns['reason']);

		$shape = $schema->shape();
		MigrationReplay::run([Version1000Date20261013100300::class], [], $schema);
		$this->assertSame($shape, $schema->shape(), 'run twice, nothing asked the second time');
	}
}
