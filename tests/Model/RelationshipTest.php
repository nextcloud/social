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

		// a string, like every other id on the wire: a client that declares
		// `id: String` cannot decode an integer, and the follow/block/mute
		// button state broke after every action that returns one of these
		$this->assertSame('5', $json['id']);
		unset($json['id'], $json['note'], $json['languages'], $json['showing_reblogs']);
		$this->assertSame(array_fill_keys([
			'following', 'notifying', 'followed_by', 'blocking', 'blocked_by',
			'muting', 'muting_notifications', 'requested', 'domain_blocking', 'endorsed',
			'requested_by',
		], false), $json);
	}

	public function testBoostsAreShownUnlessSomethingSaysOtherwise(): void {
		// boosts from a followed account do reach the home timeline and nothing
		// here turns them off per account, so `false` described a setting this
		// app does not have
		$this->assertTrue((new Relationship(5))->jsonSerialize()['showing_reblogs']);
	}

	public function testTheOptionalFieldsMastodonAlwaysSendsArePresent(): void {
		$json = (new Relationship(5))->jsonSerialize();

		$this->assertSame('', $json['note']);
		// null is Mastodon's "no language filter", which is all this app offers
		$this->assertNull($json['languages']);
		$this->assertFalse($json['requested_by']);
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
			->setEndorsed(true)
			->setRequestedBy(true)
			->setNote('a note to self')
			->setLanguages(['en']);

		$this->assertSame(3, $relationship->getId());
		$this->assertTrue($relationship->isFollowing());
		$this->assertTrue($relationship->isFollowedBy());
		$this->assertTrue($relationship->isRequested());
		$this->assertSame([
			'id' => '3',
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
			'requested_by' => true,
			'note' => 'a note to self',
			'languages' => ['en'],
		], $relationship->jsonSerialize());
	}
}
