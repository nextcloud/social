<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Like;
use PHPUnit\Framework\TestCase;

class LikeTest extends TestCase {
	public function testConstructorSetsTheType(): void {
		$this->assertSame('Like', (new Like())->getType());
	}

	public function testImportAndExportOfAMastodonLike(): void {
		$like = new Like();

		$like->import([
			'id' => 'https://mastodon.social/users/alice#likes/1',
			'type' => 'Like',
			'actor' => 'https://mastodon.social/users/alice',
			'object' => 'https://cloud.example.org/apps/social/@bob/1',
		]);
		$export = $like->jsonSerialize();

		$this->assertSame([ACore::CONTEXT_ACTIVITYSTREAMS], $export['@context']);
		$this->assertSame('https://mastodon.social/users/alice#likes/1', $export['id']);
		$this->assertSame('Like', $export['type']);
		$this->assertSame('https://mastodon.social/users/alice', $export['actor']);
		$this->assertSame('https://cloud.example.org/apps/social/@bob/1', $export['object']);
	}
}
