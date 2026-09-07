<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\Model\ActivityPub\Object\Follow;
use PHPUnit\Framework\TestCase;

class FollowTest extends TestCase {
	public function testConstructorSetsTheType(): void {
		$this->assertSame('Follow', (new Follow())->getType());
	}

	public function testImportReadsActorAndObject(): void {
		$follow = new Follow();

		$follow->import([
			'id' => 'https://mastodon.social/users/alice#follows/1',
			'type' => 'Follow',
			'actor' => 'https://mastodon.social/users/alice',
			'object' => 'https://cloud.example.org/apps/social/@bob',
		]);

		$this->assertSame('https://mastodon.social/users/alice', $follow->getActorId());
		$this->assertSame('https://cloud.example.org/apps/social/@bob', $follow->getObjectId());
		$this->assertFalse($follow->isAccepted());
	}

	public function testImportFromDatabaseReadsTheFollowColumns(): void {
		$follow = new Follow();

		$follow->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice#follows/1',
			'type' => 'Follow',
			'accepted' => 1,
			'follow_id' => 'https://cloud.example.org/apps/social/@bob#accepts/1',
			'follow_id_prim' => 'abc',
		]);

		$this->assertTrue($follow->isAccepted());
		$this->assertSame('https://cloud.example.org/apps/social/@bob#accepts/1', $follow->getFollowId());
		$this->assertSame('abc', $follow->getFollowIdPrim());
	}

	public function testJsonSerializeExposesFollowStateOnlyWithCompleteDetails(): void {
		$follow = new Follow();
		$follow->setFollowId('https://a.example/accept/1')
			->setFollowIdPrim('abc')
			->setAccepted(true);

		$json = $follow->jsonSerialize();
		$this->assertSame('Follow', $json['type']);
		$this->assertArrayNotHasKey('accepted', $json);

		$follow->setCompleteDetails(true);
		$json = $follow->jsonSerialize();
		$this->assertSame('https://a.example/accept/1', $json['follow_id']);
		$this->assertSame('abc', $json['follow_id_prim']);
		$this->assertTrue($json['accepted']);
	}
}
