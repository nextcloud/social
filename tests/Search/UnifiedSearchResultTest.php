<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Search;

use OCA\Social\Search\UnifiedSearchResult;
use OCP\Search\SearchResultEntry;
use PHPUnit\Framework\TestCase;

class UnifiedSearchResultTest extends TestCase {
	public function testDefaultsAreEmptyAndSquare(): void {
		$result = new UnifiedSearchResult();

		$this->assertInstanceOf(SearchResultEntry::class, $result);
		$this->assertSame('', $result->getThumbnailUrl());
		$this->assertSame('', $result->getTitle());
		$this->assertSame('', $result->getSubline());
		$this->assertSame('', $result->getResourceUrl());
		$this->assertSame('', $result->getIcon());
		$this->assertFalse($result->isRounded());
	}

	public function testConstructorArgumentsAreExposedThroughGetters(): void {
		$result = new UnifiedSearchResult('https://x/thumb.png', 'alice', '@alice@cloud.example', 'https://x/@alice', 'icon-user', true);

		$this->assertSame('https://x/thumb.png', $result->getThumbnailUrl());
		$this->assertSame('alice', $result->getTitle());
		$this->assertSame('@alice@cloud.example', $result->getSubline());
		$this->assertSame('https://x/@alice', $result->getResourceUrl());
		$this->assertSame('icon-user', $result->getIcon());
		$this->assertTrue($result->isRounded());
	}

	public function testSettersAreFluentAndFeedTheSerialisedEntry(): void {
		$result = (new UnifiedSearchResult())
			->setThumbnailUrl('https://x/t.png')
			->setTitle('title')
			->setSubline('sub')
			->setResourceUrl('https://x/r')
			->setIcon('icon-x')
			->setRounded(true);

		$this->assertInstanceOf(UnifiedSearchResult::class, $result);
		$json = $result->jsonSerialize();
		$this->assertSame('https://x/t.png', $json['thumbnailUrl']);
		$this->assertSame('title', $json['title']);
		$this->assertSame('sub', $json['subline']);
		$this->assertSame('https://x/r', $json['resourceUrl']);
		$this->assertSame('icon-x', $json['icon']);
		$this->assertTrue($json['rounded']);
	}
}
