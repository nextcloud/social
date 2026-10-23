<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\RecommendationProtocol;
use PHPUnit\Framework\TestCase;

class RecommendationProtocolTest extends TestCase {
	public function testUsesCanonicalBlueskyPostUrlsAndPreservesActivityPubSources(): void {
		$bluesky = (new Note())->setId('https://bsky.app/profile/did:plc:ewvi7nxzyoun6zhxrhs64oiz/post/3k');
		$activityPub = (new Note())->setId('https://remote.example/@alice/123');
		$invalidBlueskyUrl = (new Note())->setId('https://bsky.app/profile/alice.example/post/3k');

		$this->assertSame(RecommendationProtocol::ATPROTO, RecommendationProtocol::of($bluesky));
		$this->assertSame(RecommendationProtocol::ACTIVITYPUB, RecommendationProtocol::of($activityPub));
		$this->assertSame(RecommendationProtocol::ACTIVITYPUB, RecommendationProtocol::of($invalidBlueskyUrl));
	}
}
