<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;

require_once __DIR__ . '/../ActivityPubTestCase.php';

/**
 * Accept, Block, Create, Reject, Undo and Update all do the same thing on
 * arrival: look up the interface for the wrapped object and hand both items
 * over. These are the rules they share; each concrete test adds the routing
 * that matters for its own activity type.
 *
 * (Add and Remove used to be in this list. They are not dispatchers: they
 * maintain the `featured` collection — see FeaturedCollectionTest.)
 */
abstract class DispatchingActivityTestCase extends ActivityPubTestCase {
	abstract protected function createHandler(): IActivityPubInterface;

	abstract protected function activityType(): string;

	public function testActivityCarryingOnlyAnObjectIdIsIgnored(): void {
		$this->expectNoInterfaceReceivesActivity();

		$activity = $this->incoming($this->activityType(), self::REMOTE_URL . '/activities/1', self::REMOTE_URL . '/users/bob');
		$activity->setObjectId(self::REMOTE_URL . '/objects/1');

		$this->createHandler()->processIncomingRequest($activity);
	}

	public function testActivityOnObjectWithoutInterfaceIsSwallowed(): void {
		$this->expectNoInterfaceReceivesActivity();

		// Tombstone is a known model but has no handler in the registry
		$tombstone = new Tombstone();
		$tombstone->setId(self::REMOTE_URL . '/objects/1');
		$tombstone->setActorId(self::REMOTE_URL . '/users/bob');
		$activity = $this->incoming($this->activityType(), self::REMOTE_URL . '/activities/1', self::REMOTE_URL . '/users/bob', $tombstone);

		$this->createHandler()->processIncomingRequest($activity);
	}

	public function testActivityIsHandedToTheObjectsInterfaceTogetherWithTheObject(): void {
		// the sending actor on both sides of the follow, so that the one
		// fixture satisfies every dispatcher's actor rule: an Undo is only for
		// what the sender did, an Accept or Reject only for a follow addressed
		// to the sender
		$follow = new Follow();
		$follow->setId(self::REMOTE_URL . '/follows/1');
		$follow->setActorId(self::REMOTE_URL . '/users/bob');
		$follow->setObjectId(self::REMOTE_URL . '/users/bob');
		$activity = $this->incoming($this->activityType(), self::REMOTE_URL . '/activities/1', self::REMOTE_URL . '/users/bob', $follow);

		$this->followInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($activity), $this->identicalTo($follow));

		$this->createHandler()->processIncomingRequest($activity);
	}
}
