<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\SocialClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SocialClientTest extends TestCase {
	public static function scopeProvider(): array {
		return [
			'mastodon default scopes' => ['read write follow', ['read', 'write', 'follow']],
			'single scope' => ['read', ['read']],
			'granular scopes' => ['read:accounts write:statuses', ['read:accounts', 'write:statuses']],
		];
	}

	#[DataProvider('scopeProvider')]
	public function testGetScopesFromStringSplitsOnSpaces(string $scopes, array $expected): void {
		$this->assertSame($expected, (new SocialClient())->getScopesFromString($scopes));
	}

	public function testImportFromDatabaseReadsTheClientRow(): void {
		$client = new SocialClient();

		$client->importFromDatabase([
			'id' => '4',
			'app_name' => 'Tusky',
			'app_website' => 'https://tusky.app',
			'app_redirect_uris' => '["urn:ietf:wg:oauth:2.0:oob"]',
			'app_client_id' => 'client-id',
			'app_client_secret' => 'client-secret',
			'app_scopes' => '["read","write"]',
			'auth_scopes' => '["read"]',
			'auth_account' => 'alice',
			'auth_user_id' => 'alice',
			'auth_code' => 'code-123',
			'token' => 'token-456',
			'last_update' => '2024-05-01 12:00:00',
			'creation' => '2024-04-30 21:20:00',
		]);

		$this->assertSame(4, $client->getId());
		$this->assertSame('Tusky', $client->getAppName());
		$this->assertSame('https://tusky.app', $client->getAppWebsite());
		$this->assertSame(['urn:ietf:wg:oauth:2.0:oob'], $client->getAppRedirectUris());
		$this->assertSame('client-id', $client->getAppClientId());
		$this->assertSame('client-secret', $client->getAppClientSecret());
		$this->assertSame(['read', 'write'], $client->getAppScopes());
		$this->assertSame(['read'], $client->getAuthScopes());
		$this->assertSame('alice', $client->getAuthAccount());
		$this->assertSame('alice', $client->getAuthUserId());
		$this->assertSame('code-123', $client->getAuthCode());
		$this->assertSame('token-456', $client->getToken());
		$this->assertSame((new \DateTime('2024-05-01 12:00:00'))->getTimestamp(), $client->getLastUpdate());
		// `creation` is a DATE column, so the row hands over a datetime string.
		// Casting that to an int gave 2024 — a timestamp in January 1970 — which
		// is what OAuth's token response reported as `created_at`.
		$this->assertSame(
			(new \DateTime('2024-04-30 21:20:00'))->getTimestamp(), $client->getCreation()
		);
	}

	public function testAMissingCreationDateIsZeroRatherThanNow(): void {
		$client = new SocialClient();

		$client->importFromDatabase(['id' => '1', 'last_update' => '2024-05-01 12:00:00']);

		$this->assertSame(0, $client->getCreation());
	}

	public function testJsonSerializeUsesTheDatabaseColumnNames(): void {
		$client = new SocialClient();
		$client->setId(4)
			->setAppName('Tusky')
			->setAppScopes(['read'])
			->setAppClientId('id')
			->setAppClientSecret('secret')
			->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob'])
			->setAuthScopes(['read'])
			->setAuthAccount('alice')
			->setAuthUserId('alice')
			->setAuthCode('code')
			->setToken('token')
			->setLastUpdate(1714564800);
		$client->setCreation(1714500000);

		$this->assertSame([
			'id' => 4,
			'app_name' => 'Tusky',
			'app_website' => '',
			'app_scopes' => ['read'],
			'app_client_id' => 'id',
			'app_client_secret' => 'secret',
			'app_redirect_uris' => ['urn:ietf:wg:oauth:2.0:oob'],
			'auth_scopes' => ['read'],
			'auth_account' => 'alice',
			'auth_user_id' => 'alice',
			'auth_code' => 'code',
			'token' => 'token',
			'last_update' => 1714564800,
			'creation' => 1714500000,
		], $client->jsonSerialize());
	}

	public function testTimestampsDefaultToMinusOne(): void {
		$client = new SocialClient();

		$this->assertSame(-1, $client->getLastUpdate());
		$this->assertSame(-1, $client->getCreation());
	}
}
