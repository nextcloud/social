<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Model;

use OCA\Social\Tools\Model\CacheItem;
use PHPUnit\Framework\TestCase;

class CacheItemTest extends TestCase {
	public function testConstructorStoresTheUrlWithEmptyDefaults(): void {
		$item = new CacheItem('https://a.example/1');

		$this->assertSame('https://a.example/1', $item->getUrl());
		$this->assertSame('', $item->getContent());
		$this->assertSame([], $item->getObject());
		$this->assertSame(0, $item->getStatus());
		$this->assertSame(0, $item->getError());
		$this->assertSame(0, $item->getCreation());
	}

	public function testGetObjectDecodesJsonContentAndIgnoresGarbage(): void {
		$item = new CacheItem('https://a.example/1');

		$item->setContent('{"type":"Note","content":"hi"}');
		$this->assertSame(['type' => 'Note', 'content' => 'hi'], $item->getObject());

		$item->setContent('not json');
		$this->assertSame([], $item->getObject());

		$item->setContent('"a string"');
		$this->assertSame([], $item->getObject());
	}

	public function testIncrementErrorCountsRetries(): void {
		$item = new CacheItem('https://a.example/1');

		$item->incrementError()->incrementError();
		$this->assertSame(2, $item->getError());

		$item->setError(0);
		$this->assertSame(0, $item->getError());
	}

	public function testImportAndJsonSerializeRoundTrip(): void {
		$item = new CacheItem('');

		$item->import(['url' => 'https://a.example/1', 'content' => '{"a":1}', 'status' => '200', 'error' => '1', 'creation' => '1714564800']);

		$this->assertSame([
			'url' => 'https://a.example/1',
			'content' => '{"a":1}',
			'object' => ['a' => 1],
			'status' => 200,
			'error' => 1,
			'creation' => 1714564800,
		], $item->jsonSerialize());
	}
}
