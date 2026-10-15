<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\ApiController;
use OCA\Social\Controller\InstanceApiController;
use OCA\Social\Controller\MastodonApiController;
use OCA\Social\Controller\NotificationController;
use OCA\Social\Controller\OAuthController;
use OCA\Social\Controller\StatusApiController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Model\Client\SocialClient;
use OCP\Server;

/**
 * An app token -- `/oauth/token` with `grant_type=client_credentials` -- is
 * the app's own: it reads what anybody may read and acts for nobody, as on
 * Mastodon, where its own apps take one before anybody has signed in.
 */
class AppTokenTest extends ApiTestCase {
	private const APP_ID = 'apictr-app-token-id';
	private const APP_SECRET = 'apictr-app-token-secret';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$client = new SocialClient();
		$client->setAppName('apictr-app-token')
			->setAppWebsite('https://client.example')
			->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob'])
			->setAppScopes(['read', 'write', 'follow'])
			->setAppClientId(self::APP_ID)
			->setAppClientSecret(self::APP_SECRET);
		Server::get(ClientRequest::class)->saveApp($client);
	}

	#[\Override]
	protected function tearDown(): void {
		try {
			Server::get(ClientRequest::class)->deleteApp(self::APP_ID);
		} catch (\Throwable) {
		}
		parent::tearDown();
	}

	private function appToken(): string {
		$response = $this->call(
			OAuthController::class, 'token', [],
			['grant_type' => 'client_credentials', 'client_id' => self::APP_ID, 'client_secret' => self::APP_SECRET],
			null, 'POST'
		);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertSame('Bearer', $response->body->token_type);
		$this->assertSame('read', $response->body->scope, 'read and nothing more, whatever the app registered');

		return $response->body->access_token;
	}

	public function testTheGrantIssuesATokenThatReadsWhatAnybodyMayRead(): void {
		$token = $this->appToken();

		$instance = $this->call(InstanceApiController::class, 'instance', [], [], 'alice', bearer: $token);
		$this->assertSame(200, $instance->status, json_encode($instance->body) ?: '');
		$this->assertEntity('V1Instance', $instance->body);

		$public = $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'public'], [], 'alice', bearer: $token);
		$this->assertSame(200, $public->status, json_encode($public->body) ?: '');
	}

	public function testTheTokenVerifiesTheAppItBelongsTo(): void {
		$token = $this->appToken();

		$response = $this->call(ApiController::class, 'appsCredentials', [], [], 'alice', bearer: $token);

		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertSame('apictr-app-token', $response->body->name);
		$this->assertSame('https://client.example', $response->body->website);
	}

	/** @return iterable<string, array{class-string, string, array<string, mixed>}> */
	public static function accountRoutes(): iterable {
		yield 'verify_credentials' => [AccountApiController::class, 'verifyCredentials', []];
		yield 'home' => [TimelineApiController::class, 'timelines', ['timeline' => 'home']];
		yield 'favourites' => [TimelineApiController::class, 'favourites', []];
		yield 'notifications' => [TimelineApiController::class, 'notifications', []];
		yield 'grouped notifications' => [NotificationController::class, 'indexV2', []];
		yield 'preferences' => [AccountApiController::class, 'preferences', []];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('accountRoutes')]
	public function testARouteThatActsForAnAccountRefusesIt(string $controller, string $method, array $route): void {
		$token = $this->appToken();

		$response = $this->call($controller, $method, $route, [], 'alice', bearer: $token);

		$this->assertSame(401, $response->status, $method . ': ' . (json_encode($response->body) ?: ''));
		$this->assertSame(MastodonApiController::APP_TOKEN_NAMES_NOBODY, $response->body->error);
	}

	public function testAWriteIsRefusedByItsScope(): void {
		$token = $this->appToken();

		$response = $this->call(StatusApiController::class, 'statusNew', [], ['status' => 'as an app'], 'alice', 'POST', bearer: $token);

		$this->assertSame(403, $response->status, json_encode($response->body) ?: '');
	}

	public function testTheNextTokenReplacesTheLast(): void {
		$first = $this->appToken();
		$second = $this->appToken();

		$this->assertNotSame($first, $second);
		$this->assertSame(401, $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'public'], [], 'alice', bearer: $first)->status);
		$this->assertSame(200, $this->call(TimelineApiController::class, 'timelines', ['timeline' => 'public'], [], 'alice', bearer: $second)->status);
	}
}
