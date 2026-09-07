<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ClientRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Migration\HashClientSecrets;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\ClientService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The OAuth credential storage against the real social_client table: secrets,
 * codes and tokens land hashed, the token lookup resolves the plaintext a client
 * presents, legacy plaintext rows keep working until the HashClientSecrets repair
 * converts them, a fresh authorization invalidates the previous token, and
 * revocation works. The unit suite mocks ClientRequest, so none of this SQL runs
 * there.
 */
class ClientCredentialStorageTest extends TestCase {
	private const APP_NAME = 'credstore-itest';

	private ClientRequest $clientRequest;
	private ClientService $clientService;
	private IDBConnection $connection;

	protected function setUp(): void {
		parent::setUp();
		$this->clientRequest = Server::get(ClientRequest::class);
		$this->clientService = Server::get(ClientService::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete(CoreRequestBuilder::TABLE_CLIENT)
			->where($qb->expr()->eq('app_name', $qb->createNamedParameter(self::APP_NAME)));
		$qb->executeStatement();
	}

	private function registeredClient(): SocialClient {
		$client = new SocialClient();
		$client->setAppName(self::APP_NAME);
		$client->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob']);
		$client->setAppScopes(['read', 'write']);
		$this->clientService->createApp($client);

		return $client;
	}

	/** @return array<string, string> the raw row for our client */
	private function rawRow(): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('app_client_secret', 'auth_code', 'token')
			->from(CoreRequestBuilder::TABLE_CLIENT)
			->where($qb->expr()->eq('app_name', $qb->createNamedParameter(self::APP_NAME)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$this->assertNotFalse($row, 'client row exists');

		return $row;
	}

	private function setRaw(string $column, string $value): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update(CoreRequestBuilder::TABLE_CLIENT)
			->set($column, $qb->createNamedParameter($value))
			->where($qb->expr()->eq('app_name', $qb->createNamedParameter(self::APP_NAME)));
		$qb->executeStatement();
	}

	public function testTheFullAuthorizationFlowStoresOnlyDigests(): void {
		$client = $this->registeredClient();
		$this->assertStringStartsWith('sha256:', $this->rawRow()['app_client_secret']);
		$this->assertStringNotContainsString($client->getAppClientSecret(), $this->rawRow()['app_client_secret']);

		$client->setAuthScopes(['read'])->setAuthAccount(self::APP_NAME)->setAuthUserId(self::APP_NAME);
		$this->clientService->authClient($client);
		$this->assertStringStartsWith('sha256:', $this->rawRow()['auth_code']);

		$this->clientService->generateToken($client);
		$row = $this->rawRow();
		$this->assertStringStartsWith('sha256:', $row['token']);
		$this->assertSame('', $row['auth_code'], 'the code is single-use');

		// the plaintext token the client holds resolves through the hashed lookup
		$resolved = $this->clientService->getFromToken($client->getToken());
		$this->assertSame(self::APP_NAME, $resolved->getAuthUserId());
	}

	public function testAFreshAuthorizationInvalidatesThePreviousToken(): void {
		$client = $this->registeredClient();
		$client->setAuthScopes(['read'])->setAuthAccount('first')->setAuthUserId('first');
		$this->clientService->authClient($client);
		$this->clientService->generateToken($client);
		$firstToken = $client->getToken();

		$client->setAuthAccount('second')->setAuthUserId('second');
		$this->clientService->authClient($client);

		$this->expectException(ClientNotFoundException::class);
		$this->clientService->getFromToken($firstToken);
	}

	public function testRevokeTokenEndsTheSession(): void {
		$client = $this->registeredClient();
		$client->setAuthScopes(['read'])->setAuthAccount('a')->setAuthUserId('a');
		$this->clientService->authClient($client);
		$this->clientService->generateToken($client);
		$token = $client->getToken();

		$this->clientService->revokeToken($client, $token);

		$this->expectException(ClientNotFoundException::class);
		$this->clientService->getFromToken($token);
	}

	public function testALegacyPlaintextTokenStillResolvesUntilTheRepairRuns(): void {
		$client = $this->registeredClient();
		$client->setAuthScopes(['read'])->setAuthAccount('a')->setAuthUserId('legacy-user');
		$this->clientService->authClient($client);
		$this->clientService->generateToken($client);

		// devolve the row to the pre-hashing format
		$this->setRaw('token', $client->getToken());

		$resolved = $this->clientService->getFromToken($client->getToken());
		$this->assertSame('legacy-user', $resolved->getAuthUserId());

		Server::get(HashClientSecrets::class)->run($this->createMock(IOutput::class));

		$this->assertStringStartsWith('sha256:', $this->rawRow()['token'], 'the repair hashed the legacy token');
		$resolved = $this->clientService->getFromToken($client->getToken());
		$this->assertSame('legacy-user', $resolved->getAuthUserId(), 'and the plaintext still resolves');
	}

	public function testGetFromClientIdRoundTrip(): void {
		$client = $this->registeredClient();

		$read = $this->clientRequest->getFromClientId($client->getAppClientId());

		$this->assertSame(self::APP_NAME, $read->getAppName());
		$this->assertSame(['urn:ietf:wg:oauth:2.0:oob'], $read->getAppRedirectUris());
		$this->assertSame(['read', 'write'], $read->getAppScopes());
	}
}
