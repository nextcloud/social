<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\Relationship;
use PHPUnit\Framework\TestCase;

class RelationshipTest extends TestCase {
	public function testANewRelationshipHasNoFlagsSet(): void {
		$relationship = new Relationship(5);

		$json = $relationship->jsonSerialize();

		$this->assertSame(5, $json['id']);
		unset($json['id']);
		$this->assertSame(array_fill_keys([
			'following', 'showing_reblogs', 'notifying', 'followed_by', 'blocking', 'blocked_by',
			'muting', 'muting_notifications', 'requested', 'domain_blocking', 'endorsed',
		], false), $json);
	}

	public function testJsonSerializeUsesTheMastodonFieldNames(): void {
		$relationship = (new Relationship())
			->setId(3)
			->setFollowing(true)
			->setShowingReblogs(true)
			->setNotifying(true)
			->setFollowedBy(true)
			->setBlocking(true)
			->setBlockedBy(true)
			->setMuting(true)
			->setMutingNotifications(true)
			->setRequested(true)
			->setDomainBlocking(true)
			->setEndorsed(true);

		$this->assertSame(3, $relationship->getId());
		$this->assertTrue($relationship->isFollowing());
		$this->assertTrue($relationship->isFollowedBy());
		$this->assertTrue($relationship->isRequested());
		$this->assertSame([
			'id' => 3,
			'following' => true,
			'showing_reblogs' => true,
			'notifying' => true,
			'followed_by' => true,
			'blocking' => true,
			'blocked_by' => true,
			'muting' => true,
			'muting_notifications' => true,
			'requested' => true,
			'domain_blocking' => true,
			'endorsed' => true,
		], $relationship->jsonSerialize());
	}
}
