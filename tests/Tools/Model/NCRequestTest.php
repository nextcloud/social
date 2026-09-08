<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Model;

use OCA\Social\Tools\Model\NCRequest;
use OCA\Social\Tools\Model\Request;
use OCP\Http\Client\IClient;
use PHPUnit\Framework\TestCase;

class NCRequestTest extends TestCase {
	public function testIsARequestCarryingTheNextcloudHttpClient(): void {
		$client = $this->createMock(IClient::class);
		$request = new NCRequest('/inbox', Request::TYPE_POST);

		$this->assertInstanceOf(Request::class, $request);
		$this->assertSame($request, $request->setClient($client));
		$this->assertSame($client, $request->getClient());
	}

	public function testClientOptionsAndLocalAddressFlagDefaultToOff(): void {
		$request = new NCRequest();

		$this->assertSame([], $request->getClientOptions());
		$this->assertFalse($request->isLocalAddressAllowed());

		$request->setClientOptions(['timeout' => 5])->setLocalAddressAllowed(true);
		$this->assertSame(['timeout' => 5], $request->getClientOptions());
		$this->assertTrue($request->isLocalAddressAllowed());
	}

	public function testJsonSerializeExtendsTheRequestDescription(): void {
		$request = new NCRequest('/inbox');
		$request->setHost('mastodon.social');
		$request->setClientOptions(['verify' => false])->setLocalAddressAllowed(true);

		$json = $request->jsonSerialize();

		$this->assertSame('mastodon.social', $json['host']);
		$this->assertSame('/inbox', $json['url']);
		$this->assertSame(['verify' => false], $json['clientOptions']);
		$this->assertTrue($json['localAddressAllowed']);
	}
}
