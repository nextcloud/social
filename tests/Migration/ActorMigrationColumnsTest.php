<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCP\DB\Types;
use PHPUnit\Framework\TestCase;

/**
 * Where a local actor keeps its directory flags and its migration state.
 */
class ActorMigrationColumnsTest extends TestCase {
	use ReadsTheSchema;

	public function testTheFlagsAreOptInSmallints(): void {
		foreach (['discoverable', 'indexable'] as $flag) {
			[$type, $options] = $this->column('social_actor', $flag);

			$this->assertSame(Types::SMALLINT, $type);
			$this->assertTrue($options['notnull']);
			$this->assertSame(0, $options['default'], 'opt-in, like Mastodon: existing actors stay hidden');
		}
	}

	public function testTheMigrationColumnsAreNullableText(): void {
		foreach (['also_known_as', 'moved_to'] as $column) {
			[$type, $options] = $this->column('social_actor', $column);

			// an actor id is a URL of no bounded length, and a TEXT column may
			// not carry a default on MySQL
			$this->assertSame(Types::TEXT, $type);
			$this->assertFalse($options['notnull']);
			$this->assertArrayNotHasKey('default', $options);
		}
	}
}
