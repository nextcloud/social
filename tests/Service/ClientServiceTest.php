<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ClientException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Security\SecretHasher;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\MiscService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ClientServiceTest extends TestCase {
	private ClientRequest|MockObject $clientRequest;
	private ClientService $service;

	protected function setUp(): void {
		$this->clientRequest = $this->createMock(ClientRequest::class);
		$this->service = new ClientService($this->clientRequest, new SecretHasher(), $this->createMock(MiscService::class));
	}

	private function registeredClient(): SocialClient {
		$client = new SocialClient();
		$client->setAppName('Tusky');
		$client->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob', 'https://app.example/callback']);
		$client->setAppScopes(['read', 'write']);
		$client->setAuthScopes(['read']);
		$client->setAppClientSecret('s3cret');
		$client->setAuthCode('c0de');

		return $client;
	}

	public function testCreateAppGeneratesCredentialsAndSaves(): void {
		$client = $this->registeredClient();
		$this->clientRequest->expects($this->once())
			->method('saveApp')
			->with($this->identicalTo($client));

		$this->service->createApp($client);

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $client->getAppClientId());
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $client->getAppClientSecret());
		$this->assertNotSame($client->getAppClientId(), $client->getAppClientSecret());
	}

	public function testCreateAppRequiresAName(): void {
		$client = $this->registeredClient();
		$client->setAppName('');
		$this->clientRequest->expects($this->never())->method('saveApp');

		$this->expectException(ClientException::class);
		$this->expectExceptionMessage('missing client_name');
		$this->service->createApp($client);
	}

	public function testCreateAppRequiresRedirectUris(): void {
		$client = $this->registeredClient();
		$client->setAppRedirectUris([]);
		$this->clientRequest->expects($this->never())->method('saveApp');

		$this->expectException(ClientException::class);
		$this->expectExceptionMessage('missing redirect_uris');
		$this->service->createApp($client);
	}

	public function testAuthClientIssuesAnAuthCode(): void {
		$client = $this->registeredClient();
		$this->clientRequest->expects($this->once())
			->method('authClient')
			->with($this->identicalTo($client));

		$this->service->authClient($client);

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{60}$/', $client->getAuthCode());
	}

	public function testGenerateTokenIssuesABearerToken(): void {
		$client = $this->registeredClient();
		$this->clientRequest->expects($this->once())
			->method('updateToken')
			->with($this->identicalTo($client));

		$this->service->generateToken($client);

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]{80}$/', $client->getToken());
	}

	public function testGetFromClientIdDelegates(): void {
		$client = $this->registeredClient();
		$this->clientRequest->expects($this->once())
			->method('getFromClientId')
			->with('client-id')
			->willReturn($client);

		$this->assertSame($client, $this->service->getFromClientId('client-id'));
	}

	public function testGetFromTokenDoesNotRewriteAFreshlyRefreshedToken(): void {
		$client = $this->registeredClient();
		$client->setLastUpdate(time() - 60);
		$this->clientRequest->method('getFromToken')->with('tok')->willReturn($client);
		$this->clientRequest->expects($this->never())->method('updateTime');
		$this->clientRequest->expects($this->never())->method('deprecateToken');

		$this->assertSame($client, $this->service->getFromToken('tok'));
	}

	public function testGetFromTokenRefreshesATokenInUse(): void {
		// last_update follows usage (at most one write per TIME_TOKEN_REFRESH), so an
		// actively used token never ages into the TTL. The old inverted comparison
		// only rewrote recently-written rows, so real usage never refreshed anything.
		$client = $this->registeredClient();
		$client->setLastUpdate(time() - ClientService::TIME_TOKEN_REFRESH - 60);
		$this->clientRequest->method('getFromToken')->willReturn($client);
		$this->clientRequest->expects($this->once())
			->method('updateTime')
			->with($this->identicalTo($client));

		$this->assertSame($client, $this->service->getFromToken('tok'));
	}

	public function testGetFromTokenRejectsAnExpiredTokenAndPurges(): void {
		$client = $this->registeredClient();
		$client->setLastUpdate(time() - ClientService::TIME_TOKEN_TTL - 1);
		$this->clientRequest->method('getFromToken')->willReturn($client);
		$this->clientRequest->expects($this->once())->method('deprecateToken');

		$this->expectException(ClientNotFoundException::class);
		$this->service->getFromToken('tok');
	}

	public function testGetFromTokenStillRejectsWhenPurgingFails(): void {
		$client = $this->registeredClient();
		$client->setLastUpdate(0);
		$this->clientRequest->method('getFromToken')->willReturn($client);
		$this->clientRequest->method('deprecateToken')->willThrowException(new Exception('db'));

		$this->expectException(ClientNotFoundException::class);
		$this->service->getFromToken('tok');
	}

	public function testGetFromTokenPropagatesUnknownToken(): void {
		$this->clientRequest->method('getFromToken')->willThrowException(new ClientNotFoundException());

		$this->expectException(ClientNotFoundException::class);
		$this->service->getFromToken('nope');
	}

	/** @return array<string, array{array}> */
	public static function validDataProvider(): array {
		return [
			'nothing to check' => [[]],
			'known redirect' => [['redirect_uri' => 'https://app.example/callback']],
			'right secret' => [['client_secret' => 's3cret']],
			'registered app scopes as array' => [['app_scopes' => ['read']]],
			'registered app scopes as string' => [['app_scopes' => 'read write']],
			'granted auth scopes' => [['auth_scopes' => 'read']],
			'right code' => [['code' => 'c0de']],
			'everything at once' => [[
				'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
				'client_secret' => 's3cret',
				'app_scopes' => 'write',
				'auth_scopes' => ['read'],
				'code' => 'c0de',
			]],
		];
	}

	/** @dataProvider validDataProvider */
	public function testConfirmDataAcceptsMatchingData(array $data): void {
		$this->service->confirmData($this->registeredClient(), $data);
		$this->addToAssertionCount(1);
	}

	/** @return array<string, array{array, string}> */
	public static function invalidDataProvider(): array {
		return [
			'unknown redirect' => [['redirect_uri' => 'https://evil.example/'], 'unknown redirect_uri'],
			'wrong secret' => [['client_secret' => 'nope'], 'wrong client_secret'],
			'more app scopes than registered' => [['app_scopes' => 'read write follow'], 'invalid scope'],
			'more auth scopes than granted' => [['auth_scopes' => ['read', 'write']], 'invalid scope'],
			'wrong code' => [['code' => 'other'], 'unknown code'],
		];
	}

	/** @dataProvider invalidDataProvider */
	public function testConfirmDataRejectsMismatches(array $data, string $message): void {
		$this->expectException(ClientException::class);
		$this->expectExceptionMessage($message);
		$this->service->confirmData($this->registeredClient(), $data);
	}

	public function testConfirmDataAcceptsSecretsStoredHashed(): void {
		$hasher = new SecretHasher();
		$client = $this->registeredClient();
		$client->setAppClientSecret($hasher->hash('s3cret'));
		$client->setAuthCode($hasher->hash('c0de'));
		$client->setLastUpdate(time() - 60);

		$this->service->confirmData($client, ['client_secret' => 's3cret', 'code' => 'c0de']);
		$this->addToAssertionCount(1);
	}

	public function testConfirmDataRejectsTheStoredHashAsThePresentedSecret(): void {
		$hasher = new SecretHasher();
		$client = $this->registeredClient();
		$client->setAppClientSecret($hasher->hash('s3cret'));

		// a database leak must not hand out a working credential
		$this->expectException(ClientException::class);
		$this->service->confirmData($client, ['client_secret' => $hasher->hash('s3cret')]);
	}

	public function testConfirmDataRejectsAnExpiredCode(): void {
		$client = $this->registeredClient();
		$client->setLastUpdate(time() - ClientService::TIME_CODE_TTL - 60);

		$this->expectException(ClientException::class);
		$this->expectExceptionMessage('code expired');
		$this->service->confirmData($client, ['code' => 'c0de']);
	}

	public function testConfirmDataAcceptsAFreshCode(): void {
		$client = $this->registeredClient();
		$client->setLastUpdate(time() - 60);

		$this->service->confirmData($client, ['code' => 'c0de']);
		$this->addToAssertionCount(1);
	}

	public function testRevokeTokenClearsTheClientsOwnToken(): void {
		$client = $this->registeredClient();
		$client->setId(7);
		$this->clientRequest->method('getFromToken')->with('tok')->willReturn($client);
		$this->clientRequest->expects($this->once())
			->method('revokeToken')
			->with($this->identicalTo($client));

		$this->service->revokeToken($client, 'tok');
	}

	public function testRevokeTokenRefusesAnotherClientsToken(): void {
		$owner = $this->registeredClient();
		$owner->setId(7);
		$caller = $this->registeredClient();
		$caller->setId(8);
		$this->clientRequest->method('getFromToken')->willReturn($owner);
		$this->clientRequest->expects($this->never())->method('revokeToken');

		$this->expectException(ClientException::class);
		$this->service->revokeToken($caller, 'tok');
	}
}
