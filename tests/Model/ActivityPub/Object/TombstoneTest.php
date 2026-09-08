<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\Model\ActivityPub\Object\Tombstone;
use PHPUnit\Framework\TestCase;

class TombstoneTest extends TestCase {
	public function testConstructorSetsTheType(): void {
		$this->assertSame('Tombstone', (new Tombstone())->getType());
	}

	public function testImportKeepsTheIdOfTheDeletedObject(): void {
		$tombstone = new Tombstone();

		$tombstone->import(['id' => 'https://mastodon.social/users/alice/statuses/1', 'type' => 'Tombstone']);

		$this->assertSame('https://mastodon.social/users/alice/statuses/1', $tombstone->getId());
		$this->assertSame(
			['id' => 'https://mastodon.social/users/alice/statuses/1', 'type' => 'Tombstone'],
			array_intersect_key($tombstone->jsonSerialize(), ['id' => 1, 'type' => 1])
		);
	}
}
