<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AuthorizedAppsController;
use OCA\Social\Controller\ChannelController;
use OCA\Social\Controller\MemoriesController;
use OCA\Social\Controller\MigrationController;
use OCA\Social\Controller\StatisticsController;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\NetworkStatsService;
use OCP\IAppConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The account pages a mobile client opens with the token it signed in with:
 * memories, statistics, video channels, the exports, imports and moving in,
 * and the authorized apps.
 *
 * Each route is called five ways against the real server: with a bearer
 * token granted the route's scope, with one granted something else (403),
 * with nothing (401), as the web page does with the Nextcloud session and its
 * CSRF token, and as a client holding a Nextcloud app password does — Basic
 * auth that Nextcloud signs in, and `OCS-APIRequest: true` in place of the
 * CSRF token. The last three answer the same as the first, so a client can
 * move from the app password to the token without its reader noticing.
 *
 * Where a route would otherwise reach another server or write something
 * lasting, it is called with an argument it refuses — no file, no handle —
 * and that refusal (400, 404, 422) is what being let in looks like.
 */
class SignInTokenRoutesTest extends ApiTestCase {
	private ?string $networkStats = null;

	protected function setUp(): void {
		parent::setUp();
		// the statistics would otherwise ask FediDB how big the network is
		$config = Server::get(IAppConfig::class);
		$this->networkStats = $config->hasKey('social', NetworkStatsService::CONFIG_KEY)
			? $config->getValueString('social', NetworkStatsService::CONFIG_KEY) : null;
		$config->setValueString('social', NetworkStatsService::CONFIG_KEY, '0');
	}

	protected function tearDown(): void {
		$config = Server::get(IAppConfig::class);
		if ($this->networkStats === null) {
			$config->deleteKey('social', NetworkStatsService::CONFIG_KEY);
		} else {
			$config->setValueString('social', NetworkStatsService::CONFIG_KEY, $this->networkStats);
		}
		parent::tearDown();
	}

	/**
	 * @return array<string, array{class-string, string, array<string, mixed>, array<string, mixed>, string, string[], string[], int}>
	 *                                                                                                                                 controller, method, route parameters, query or body, verb, a scope that serves it, one that does not, the answer once let in
	 */
	public static function routes(): array {
		$routes = [
			'memories on this day' => [MemoriesController::class, 'onThisDay', [], [], 'GET', ['read:statuses'], ['read:accounts'], 200],
			'the recap' => [MemoriesController::class, 'recap', [], [], 'GET', ['read:statuses'], ['read:accounts'], 200],
			'switching the recap' => [MemoriesController::class, 'setRecap', [], ['enabled' => '1'], 'POST', ['write:accounts'], ['read', 'write:statuses'], 200],
			'the statistics' => [StatisticsController::class, 'statistics', [], ['days' => '30'], 'GET', ['read:statuses'], ['read:accounts'], 200],
			'the statistics as a file' => [StatisticsController::class, 'export', [], ['days' => '30'], 'GET', ['read:statuses'], ['read:accounts', 'write'], 200],
			'the channels' => [ChannelController::class, 'index', [], [], 'GET', ['read:accounts'], ['read:statuses'], 200],
			'making a channel' => [ChannelController::class, 'create', [], ['handle' => ''], 'POST', ['write:accounts'], ['read', 'write:statuses'], 422],
			'changing a channel' => [ChannelController::class, 'update', ['id' => '999999999'], ['name' => 'x'], 'PUT', ['write:accounts'], ['read:accounts'], 422],
			'the archive' => [MigrationController::class, 'export', [], [], 'GET', ['read'], ['read:statuses', 'read:accounts', 'read:follows'], 200],
			'restoring the archive' => [MigrationController::class, 'import', [], [], 'POST', ['write'], ['write:accounts'], 400],
			'importing follows' => [MigrationController::class, 'importFollows', [], [], 'POST', ['write'], ['write:follows', 'follow'], 400],
			'importing posts' => [MigrationController::class, 'importPosts', [], [], 'POST', ['write'], ['write:statuses'], 400],
			'importing a video' => [MigrationController::class, 'importVideo', [], ['url' => ''], 'POST', ['write'], ['write:media'], 422],
			'inspecting the old account' => [MigrationController::class, 'moveInInspect', [], ['handle' => ''], 'POST', ['write'], ['read'], 422],
			'moving in' => [MigrationController::class, 'moveIn', [], ['handle' => ''], 'POST', ['write'], ['write:accounts'], 422],
			'finishing the move' => [MigrationController::class, 'moveInFinishStart', [], ['handle' => ''], 'POST', ['write'], ['write:accounts'], 422],
			'where the finish stands' => [MigrationController::class, 'moveInFinishStatus', [], [], 'GET', ['read'], ['read:accounts'], 200],
			'the imports' => [MigrationController::class, 'imports', [], [], 'GET', ['read'], ['read:statuses'], 200],
			'dismissing an import' => [MigrationController::class, 'dismissImport', ['id' => '999999999'], [], 'DELETE', ['write'], ['read'], 404],
			'the authorized apps' => [AuthorizedAppsController::class, 'index', [], [], 'GET', ['read'], ['read:accounts'], 200],
		];

		foreach ([
			'following' => ['read:follows', 'read:blocks'],
			'followers' => ['read:follows', 'read:accounts'],
			'blocks' => ['read:blocks', 'read:mutes'],
			'domain_blocks' => ['read:blocks', 'read:follows'],
			'mutes' => ['read:mutes', 'read:blocks'],
			'lists' => ['read:lists', 'read:follows'],
			'bookmarks' => ['read:bookmarks', 'read:favourites'],
		] as $kind => [$scope, $other]) {
			$routes['the ' . $kind . ' CSV'] = [MigrationController::class, 'exportCsv', ['kind' => $kind], [], 'GET', [$scope], [$other], 200];
		}

		return $routes;
	}

	/**
	 * @param class-string $controller
	 * @param array<string, mixed> $route
	 * @param array<string, mixed> $query
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testATokenGrantedTheRouteScopeIsLetIn(string $controller, string $method, array $route, array $query, string $verb, array $granted, array $other, int $status): void {
		$response = $this->call(
			$controller, $method, $route, $query, 'alice', $verb, bearer: $this->scopedToken('alice', $granted)
		);

		$this->assertSame($status, $response->status, json_encode($response->body) ?: '');
	}

	/**
	 * @param class-string $controller
	 * @param array<string, mixed> $route
	 * @param array<string, mixed> $query
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testATokenWithoutTheRouteScopeIsRefused(string $controller, string $method, array $route, array $query, string $verb, array $granted, array $other, int $status): void {
		$response = $this->call(
			$controller, $method, $route, $query, 'alice', $verb, bearer: $this->scopedToken('alice', $other)
		);

		$this->assertSame(403, $response->status, json_encode($response->body) ?: '');
		$this->assertArrayNotHasKey('WWW-Authenticate', $response->headers);
	}

	/**
	 * @param class-string $controller
	 * @param array<string, mixed> $route
	 * @param array<string, mixed> $query
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testNobodyIsRefused(string $controller, string $method, array $route, array $query, string $verb, array $granted, array $other, int $status): void {
		$response = $this->call($controller, $method, $route, $query, null, $verb);

		$this->assertSame(401, $response->status, json_encode($response->body) ?: '');
	}

	/**
	 * @param class-string $controller
	 * @param array<string, mixed> $route
	 * @param array<string, mixed> $query
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testTheWebPageIsLetInThroughItsSession(string $controller, string $method, array $route, array $query, string $verb, array $granted, array $other, int $status): void {
		$response = $this->call($controller, $method, $route, $query, 'alice', $verb, webSession: true);

		$this->assertSame($status, $response->status, json_encode($response->body) ?: '');
	}

	/**
	 * @param class-string $controller
	 * @param array<string, mixed> $route
	 * @param array<string, mixed> $query
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testAClientWithANextcloudAppPasswordIsStillLetIn(string $controller, string $method, array $route, array $query, string $verb, array $granted, array $other, int $status): void {
		$response = $this->call($controller, $method, $route, $query, 'alice', $verb, appPassword: true);

		$this->assertSame($status, $response->status, json_encode($response->body) ?: '');
	}

	/** What the token reads is its own account's: bob's channels are not alice's. */
	public function testATokenReadsItsOwnAccount(): void {
		$this->account('alice');
		$token = $this->scopedToken('bob', ['read:accounts']);

		$response = $this->call(ChannelController::class, 'index', [], [], 'bob', bearer: $token);

		$this->assertSame(200, $response->status);
		foreach ($response->body->channels as $channel) {
			$this->assertStringNotContainsString(self::PREFIX . 'alice', json_encode($channel) ?: '');
		}
	}

	// the authorized apps

	/** The authorization row behind a token. */
	private function authId(string $token): int {
		return Server::get(ClientAuthRequest::class)->getByToken($token)->getAuthId();
	}

	private function tokenWorks(string $token): bool {
		try {
			Server::get(ClientService::class)->getFromToken($token);

			return true;
		} catch (ClientNotFoundException) {
			return false;
		}
	}

	/** An app signing itself out with its own token: the authorization is gone, and the token with it. */
	public function testATokenRevokesItsOwnAuthorization(): void {
		$token = $this->scopedToken('alice', ['read', 'write']);

		$response = $this->call(
			AuthorizedAppsController::class, 'revoke', ['id' => (string)$this->authId($token)], [], 'alice', 'DELETE', bearer: $token
		);

		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertFalse($this->tokenWorks($token));
	}

	/**
	 * Another app's authorization, of the same account, is not a token's to
	 * take back whatever it was granted: 403, and the other app stays signed in.
	 */
	public function testATokenCannotRevokeAnotherAppsAuthorization(): void {
		$other = $this->scopedToken('alice', ['read']);
		$token = $this->scopedToken('alice', ['read', 'write', 'follow', 'push']);

		$response = $this->call(
			AuthorizedAppsController::class, 'revoke', ['id' => (string)$this->authId($other)], [], 'alice', 'DELETE', bearer: $token
		);

		$this->assertSame(403, $response->status, json_encode($response->body) ?: '');
		$this->assertTrue($this->tokenWorks($other));
	}

	/** A token without `write` may not sign out even itself through this route. */
	public function testRevokingItsOwnAuthorizationNeedsWrite(): void {
		$token = $this->scopedToken('alice', ['read']);

		$response = $this->call(
			AuthorizedAppsController::class, 'revoke', ['id' => (string)$this->authId($token)], [], 'alice', 'DELETE', bearer: $token
		);

		$this->assertSame(403, $response->status);
		$this->assertTrue($this->tokenWorks($token));
	}

	/** The settings page signs any of the account's apps out through the session. */
	public function testTheWebPageRevokesAnotherAppsAuthorization(): void {
		$other = $this->scopedToken('alice', ['read']);

		$response = $this->call(
			AuthorizedAppsController::class, 'revoke', ['id' => (string)$this->authId($other)], [], 'alice', 'DELETE', webSession: true
		);

		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertFalse($this->tokenWorks($other));
	}

	/** So does a client signed in with a Nextcloud app password, as before. */
	public function testAnAppPasswordRevokesAnotherAppsAuthorization(): void {
		$other = $this->scopedToken('alice', ['read']);

		$response = $this->call(
			AuthorizedAppsController::class, 'revoke', ['id' => (string)$this->authId($other)], [], 'alice', 'DELETE', appPassword: true
		);

		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertFalse($this->tokenWorks($other));
	}

	/** Through the session, another account's authorization is the 404 a missing id is. */
	public function testTheWebPageCannotRevokeAnotherAccountsAuthorization(): void {
		$bobs = $this->scopedToken('bob', ['read']);

		$response = $this->call(
			AuthorizedAppsController::class, 'revoke', ['id' => (string)$this->authId($bobs)], [], 'alice', 'DELETE', webSession: true
		);

		$this->assertSame(404, $response->status);
		$this->assertTrue($this->tokenWorks($bobs));
	}
}
