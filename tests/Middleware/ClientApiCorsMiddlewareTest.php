<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Middleware;

use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\AdminApiController;
use OCA\Social\Controller\AnnouncementController;
use OCA\Social\Controller\ClientApiPreflightController;
use OCA\Social\Controller\InstanceApiController;
use OCA\Social\Controller\ListController;
use OCA\Social\Controller\LocalController;
use OCA\Social\Controller\MediaApiController;
use OCA\Social\Controller\NavigationController;
use OCA\Social\Controller\OAuthController;
use OCA\Social\Controller\StatusApiController;
use OCA\Social\Controller\TimelineApiController;
use OCA\Social\Middleware\ClientApiCorsMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which answers a browser client of another origin may read: the routes that
 * take a bearer token, Mastodon's `/api/*` and OAuth endpoints, and never the
 * web app's own session routes. Never with credentials.
 */
class ClientApiCorsMiddlewareTest extends TestCase {
	protected function setUp(): void {
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function answer(string $controller, string $method, string $path, ?Response $response = null): Response {
		$request = $this->createStub(IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		/** @var Controller $instance */
		$instance = $this->createStub($controller);

		return (new ClientApiCorsMiddleware($request))
			->afterController($instance, $method, $response ?? new DataResponse(['ok' => true]));
	}

	/** @return array<string, array{class-string, string, string}> */
	public static function openRoutes(): array {
		return [
			'a Mastodon route' => [TimelineApiController::class, 'timelines', '/apps/social/api/v1/timelines/home'],
			'an account route' => [AccountApiController::class, 'verifyCredentials', '/apps/social/api/v1/accounts/verify_credentials'],
			'a client-API route' => [ListController::class, 'lists', '/apps/social/api/v1/lists'],
			'an admin API route' => [AdminApiController::class, 'accounts', '/apps/social/api/v1/admin/accounts'],
			'the instance' => [InstanceApiController::class, 'instanceV2', '/apps/social/api/v2/instance'],
			'app registration' => [OAuthController::class, 'apps', '/apps/social/api/v1/apps'],
			'the token endpoint' => [OAuthController::class, 'token', '/apps/social/oauth/token'],
			'revocation' => [OAuthController::class, 'revoke', '/apps/social/oauth/revoke'],
			'userinfo' => [OAuthController::class, 'userinfo', '/apps/social/oauth/userinfo'],
			'NodeInfo' => [OAuthController::class, 'nodeinfo21', '/apps/social/.well-known/nodeinfo/2.1'],
			'a preflight' => [ClientApiPreflightController::class, 'preflight', '/apps/social/api/v1/statuses'],
			'a route reached at the root' => [TimelineApiController::class, 'timelines', '/index.php/apps/social/api/v1/timelines/public'],
		];
	}

	/** @param class-string $controller */
	#[DataProvider('openRoutes')]
	public function testABearerRouteIsOpenToAnyOriginWithoutCredentials(string $controller, string $method, string $path): void {
		$headers = $this->answer($controller, $method, $path)->getHeaders();

		$this->assertSame('*', $headers['Access-Control-Allow-Origin'] ?? null);
		$this->assertStringContainsString('Authorization', $headers['Access-Control-Allow-Headers'] ?? '');
		$this->assertStringContainsString('Idempotency-Key', $headers['Access-Control-Allow-Headers']);
		$this->assertStringContainsString('Link', $headers['Access-Control-Expose-Headers'] ?? '');
		$this->assertStringContainsString('X-RateLimit-Remaining', $headers['Access-Control-Expose-Headers']);
		$this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
	}

	/** @return array<string, array{class-string, string, string}> */
	public static function closedRoutes(): array {
		return [
			'a web-app session route under /api/' => [LocalController::class, 'globalAccountInfo', '/apps/social/api/v1/global/account/info'],
			'a page' => [NavigationController::class, 'navigate', '/apps/social/'],
			'an admin page route on an API base' => [AnnouncementController::class, 'adminIndex', '/apps/social/admin/announcements'],
			'a file route on an API base' => [MediaApiController::class, 'mediaOpen', '/apps/social/media/abc'],
			'the consent page' => [OAuthController::class, 'authorize', '/apps/social/oauth/authorize'],
		];
	}

	/** @param class-string $controller */
	#[DataProvider('closedRoutes')]
	public function testAnyOtherRouteAnswersNoOtherOrigin(string $controller, string $method, string $path): void {
		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $this->answer($controller, $method, $path)->getHeaders());
	}

	/** A refusal is readable too, so a client can tell an expired token from a network failure. */
	public function testAnErrorAnswerCarriesTheHeaders(): void {
		$refused = new DataResponse(['error' => 'The access token is invalid'], Http::STATUS_UNAUTHORIZED);

		$this->assertSame('*', $this->answer(StatusApiController::class, 'status', '/apps/social/api/v1/statuses/1', $refused)->getHeaders()['Access-Control-Allow-Origin'] ?? null);
	}

	/** The AT Protocol token path names DPoP among its headers, and keeps it. */
	public function testAnAnswerThatAlreadyNamesItsCorsHeadersKeepsThem(): void {
		$response = new DataResponse([]);
		$response->addHeader('Access-Control-Allow-Origin', '*');
		$response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, DPoP');

		$headers = $this->answer(OAuthController::class, 'token', '/apps/social/oauth/token', $response)->getHeaders();

		$this->assertSame('Authorization, Content-Type, DPoP', $headers['Access-Control-Allow-Headers']);
	}

	/** An endpoint this server does not have is a 404 the client can read. */
	public function testAMissingEndpointIsANotFoundAnyOriginReads(): void {
		$response = (new ClientApiPreflightController($this->createStub(IRequest::class)))->notFound('v1/streaming/');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin'] ?? null);
		$this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->getHeaders());
	}

	/**
	 * Either header makes Nextcloud take a session as CSRF-checked, so a
	 * preflight never lets another origin send one.
	 */
	public function testThePreflightNeverAllowsAHeaderThatStandsInForTheCsrfToken(): void {
		$response = (new ClientApiPreflightController($this->createStub(IRequest::class)))->preflight('v1/statuses');
		$allowed = array_map('strtolower', array_map('trim', explode(',', $response->getHeaders()['Access-Control-Allow-Headers'])));

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertNotContains('ocs-apirequest', $allowed);
		$this->assertNotContains('requesttoken', $allowed);
		$this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->getHeaders());
	}
}
