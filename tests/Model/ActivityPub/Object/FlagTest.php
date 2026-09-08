<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\Model\ActivityPub\Object\Flag;
use PHPUnit\Framework\TestCase;

class FlagTest extends TestCase {
	public function testImportReadsAMastodonFlag(): void {
		// verbatim shape of a Mastodon report on the wire
		$flag = new Flag();
		$flag->import([
			'id' => 'https://mastodon.social/reports/1',
			'type' => 'Flag',
			'actor' => 'https://mastodon.social/actor',
			'content' => 'Please have a look at this account',
			'object' => [
				'https://cloud.example/apps/social/@alice',
				'https://cloud.example/apps/social/@alice/123',
			],
		]);

		$this->assertSame('https://mastodon.social/actor', $flag->getActorId());
		$this->assertSame('Please have a look at this account', $flag->getContent());
		$this->assertSame(
			[
				'https://cloud.example/apps/social/@alice',
				'https://cloud.example/apps/social/@alice/123',
			],
			$flag->getObjectIds()
		);
		$this->assertSame('https://cloud.example/apps/social/@alice', $flag->getObjectId());
	}

	public function testImportAcceptsASingleObjectId(): void {
		$flag = new Flag();
		$flag->import([
			'id' => 'https://remote.example/reports/2',
			'type' => 'Flag',
			'actor' => 'https://remote.example/actor',
			'object' => 'https://cloud.example/apps/social/@alice',
		]);

		$this->assertSame(['https://cloud.example/apps/social/@alice'], $flag->getObjectIds());
		$this->assertSame('https://cloud.example/apps/social/@alice', $flag->getObjectId());
	}

	public function testImportDropsNonStringObjectEntries(): void {
		$flag = new Flag();
		$flag->import([
			'type' => 'Flag',
			'object' => ['https://cloud.example/apps/social/@alice', 42, '', ['nested']],
		]);

		$this->assertSame(['https://cloud.example/apps/social/@alice'], $flag->getObjectIds());
	}

	public function testJsonSerializeCarriesObjectsAndContent(): void {
		$flag = new Flag();
		$flag->setObjectIds(['https://cloud.example/apps/social/@alice']);
		$flag->setContent('spam');

		$data = $flag->jsonSerialize();

		$this->assertSame('Flag', $data['type']);
		$this->assertSame(['https://cloud.example/apps/social/@alice'], $data['object']);
		$this->assertSame('spam', $data['content']);
	}
}
