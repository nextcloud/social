<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\InstancePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstancePathTest extends TestCase {
	public static function uriProvider(): array {
		return [
			'shared inbox' => ['https://mastodon.social/inbox', 'https', 'mastodon.social', '/inbox'],
			'user inbox with port' => ['http://localhost:8080/users/alice/inbox', 'http', 'localhost', '/users/alice/inbox'],
			'host only' => ['https://mastodon.social', 'https', 'mastodon.social', ''],
			'empty' => ['', '', '', ''],
		];
	}

	#[DataProvider('uriProvider')]
	public function testUriIsSplitIntoProtocolAddressAndPath(string $uri, string $protocol, string $address, string $path): void {
		$instance = new InstancePath($uri);

		$this->assertSame($protocol, $instance->getProtocol());
		$this->assertSame($address, $instance->getAddress());
		$this->assertSame($path, $instance->getPath());
	}

	public function testConstructorDefaultsToPublicTypeAndNoPriority(): void {
		$instance = new InstancePath('https://mastodon.social/inbox');

		$this->assertSame(InstancePath::TYPE_PUBLIC, $instance->getType());
		$this->assertSame(InstancePath::PRIORITY_NONE, $instance->getPriority());
	}

	public function testImportAndJsonSerializeRoundTrip(): void {
		$instance = new InstancePath();

		$instance->import(['uri' => 'https://mastodon.social/inbox', 'type' => '3', 'priority' => '4']);

		$this->assertSame('https://mastodon.social/inbox', $instance->getUri());
		$this->assertSame(InstancePath::TYPE_FOLLOWERS, $instance->getType());
		$this->assertSame(InstancePath::PRIORITY_TOP, $instance->getPriority());
		$this->assertSame(
			['uri' => 'https://mastodon.social/inbox', 'type' => 3, 'priority' => 4],
			$instance->jsonSerialize()
		);
	}

	public function testSettersAreFluent(): void {
		$instance = (new InstancePath())
			->setUri('https://a.example/inbox')
			->setType(InstancePath::TYPE_INBOX)
			->setPriority(InstancePath::PRIORITY_MEDIUM);

		$this->assertSame('https://a.example/inbox', $instance->getUri());
		$this->assertSame(1, $instance->getType());
		$this->assertSame(2, $instance->getPriority());
	}
}
