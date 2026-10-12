<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OC\AppFramework\DependencyInjection\DIContainer;
use OC\AppFramework\Http\Dispatcher;
use OC\AppFramework\Http\Request;
use OC\Route\Router;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\Social\AppInfo\Application;
use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\ClientApiPreflightController;
use OCA\Social\Controller\InstanceApiController;
use OCA\Social\Controller\LocalController;
use OCA\Social\Controller\OAuthController;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\Diagnostics\IEventLogger;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * The client API as a browser client of another origin meets it, through the
 * real dispatcher and every middleware the app registers: readable with a
 * bearer token, the preflight answered without one, never with credentials,
 * and the web app's session still held to its CSRF check when the request
 * comes from elsewhere.
 */
class ClientApiCorsTest extends ApiTestCase {
	private const ORIGIN = 'https://elsewhere.example';

	#[\Override]
	protected function accountNames(): array {
		return ['corsalice'];
	}

	/** @return list<class-string> */
	private function appMiddlewares(): array {
		$registered = [];
		$context = $this->createStub(IRegistrationContext::class);
		$context->method('registerMiddleware')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);
		(new Application())->register($context);

		return $registered;
	}

	/**
	 * @param class-string $class
	 * @param array<string, string> $server
	 * @param array<string, string> $cookies
	 * @param array<string, string> $route the route's own parameters
	 */
	private function dispatch(string $class, string $method, string $verb, string $path, array $server = [], array $cookies = [], array $route = []): Response {
		$request = new Request(
			[
				'server' => array_merge([
					'REQUEST_METHOD' => $verb,
					'REQUEST_URI' => '/index.php' . $path,
					'SCRIPT_NAME' => '/index.php',
					'HTTP_ORIGIN' => self::ORIGIN,
				], $server),
				'cookies' => $cookies,
				'method' => $verb,
				'urlParams' => array_merge($route, ['_route' => 'social.cors.' . $method]),
			],
			Server::get(IRequestId::class),
			Server::get(IConfig::class),
			Server::get(CsrfTokenManager::class),
		);

		$container = new DIContainer('social');
		$container->registerService(IRequest::class, static fn (): IRequest => $request);
		foreach ($this->appMiddlewares() as $middleware) {
			$container->registerMiddleWare($middleware);
		}

		return $container->get(Dispatcher::class)->dispatch($container->get($class), $method)[4];
	}

	/** @param array<string, string> $headers */
	private function assertOpenWithoutCredentials(array $headers, string $what): void {
		$this->assertSame('*', $headers['Access-Control-Allow-Origin'] ?? null, $what . ' is readable from any origin');
		$this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers, $what . ' never with credentials');
	}

	public function testABearerCallFromAnotherOriginIsReadable(): void {
		$response = $this->dispatch(AccountApiController::class, 'verifyCredentials', 'GET', '/apps/social/api/v1/accounts/verify_credentials', [
			'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token('corsalice'),
		]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertOpenWithoutCredentials($response->getHeaders(), 'verify_credentials');
		$this->assertStringContainsString('X-RateLimit-Remaining', $response->getHeaders()['Access-Control-Expose-Headers']);
	}

	public function testAnAnonymousReadAndARefusalAreReadableToo(): void {
		$instance = $this->dispatch(InstanceApiController::class, 'instanceV2', 'GET', '/apps/social/api/v2/instance');
		$this->assertSame(Http::STATUS_OK, $instance->getStatus());
		$this->assertOpenWithoutCredentials($instance->getHeaders(), 'the instance');

		$token = $this->dispatch(OAuthController::class, 'token', 'POST', '/apps/social/oauth/token');
		$this->assertGreaterThanOrEqual(400, $token->getStatus(), 'no client, no token');
		$this->assertOpenWithoutCredentials($token->getHeaders(), 'the token endpoint\'s refusal');

		$revoked = $this->dispatch(AccountApiController::class, 'verifyCredentials', 'GET', '/apps/social/api/v1/accounts/verify_credentials', [
			'HTTP_AUTHORIZATION' => 'Bearer not-a-token',
		]);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $revoked->getStatus());
		$this->assertOpenWithoutCredentials($revoked->getHeaders(), 'a refused token');
	}

	public function testThePreflightIsAnsweredWithoutCredentials(): void {
		$response = $this->dispatch(ClientApiPreflightController::class, 'preflight', 'OPTIONS', '/apps/social/api/v1/statuses', [
			'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
			'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type, idempotency-key',
		], [], ['path' => 'v1/statuses']);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertOpenWithoutCredentials($response->getHeaders(), 'the preflight');
		$this->assertStringContainsString('POST', $response->getHeaders()['Access-Control-Allow-Methods']);
	}

	/**
	 * The session cookie arrives from another origin without the same-site
	 * cookie and without a CSRF token, as a browser sends it there: no viewer.
	 * The same session from the web app, token and all, still signs in.
	 */
	public function testASessionCookieFromAnotherOriginIsStillRefused(): void {
		$this->account('corsalice');
		$session = Server::get(IUserSession::class);
		$session->setUser(Server::get(IUserManager::class)->get(self::PREFIX . 'corsalice'));
		try {
			$crossSite = $this->dispatch(AccountApiController::class, 'verifyCredentials', 'GET', '/apps/social/api/v1/accounts/verify_credentials', [], [
				session_name() => 'the-session',
			]);
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $crossSite->getStatus(), 'another origin does not ride the session');
			$this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $crossSite->getHeaders());

			$webApp = $this->dispatch(AccountApiController::class, 'verifyCredentials', 'GET', '/apps/social/api/v1/accounts/verify_credentials', [
				'HTTP_REQUESTTOKEN' => Server::get(CsrfTokenManager::class)->getToken()->getEncryptedValue(),
				'HTTP_ORIGIN' => '',
			]);
			$this->assertSame(Http::STATUS_OK, $webApp->getStatus(), 'the web app\'s own call still signs in');
		} finally {
			$session->setUser(null);
		}
	}

	public function testAWebAppRouteOutsideTheApiBasesAnswersNoOtherOrigin(): void {
		$response = $this->dispatch(LocalController::class, 'globalTagsSearch', 'GET', '/apps/social/api/v1/global/tags/search');

		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->getHeaders());
	}

	/** The server's own router, freshly built with the app loaded, so routes.php is read too. */
	private function router(): Router {
		Server::get(IAppManager::class)->loadApp('social');

		$router = new Router(
			Server::get(LoggerInterface::class), Server::get(IRequest::class), Server::get(IConfig::class),
			Server::get(IEventLogger::class), \OC::$server, Server::get(IAppManager::class),
		);
		$router->loadRoutes('social');

		return $router;
	}

	/** The route the server dispatches a request to, or the exception it answers with. */
	private function routeOf(Router $router, string $verb, string $path): string {
		$router->setContext(new RequestContext('', $verb));
		try {
			return (string)$router->findMatchingRoute($path)['_route'];
		} catch (\Throwable $e) {
			return get_class($e);
		}
	}

	/**
	 * The catch-alls behind the client API take only what no route takes:
	 * every route under /api/ is still reached with its own verb, with a slash
	 * after it or not where the route ends in one, every one of their paths
	 * has its preflight answered, and an endpoint that does not exist is the
	 * catch-all's 404 rather than a 405.
	 */
	public function testTheCatchAllsAnswerOnlyWhatNoRouteTakes(): void {
		$router = $this->router();
		$catchAll = 'social.clientapipreflight.';
		$checked = 0;
		$stolen = [];
		foreach ($router->getRouteCollection()->all() as $name => $route) {
			$path = $route->getPath();
			if (!str_starts_with($path, '/apps/social/api/') || str_starts_with($name, $catchAll)) {
				continue;
			}
			$probe = (string)preg_replace('/\\{\\w+\\}/', '1', $path);
			$probes = str_ends_with($probe, '/') ? [$probe, rtrim($probe, '/')] : [$probe];
			foreach ($route->getMethods() as $verb) {
				foreach ($probes as $each) {
					$checked++;
					if (str_starts_with($this->routeOf($router, $verb, $each), $catchAll)) {
						$stolen[] = $verb . ' ' . $each . ' (' . $name . ')';
					}
				}
			}
			$preflight = $this->routeOf($router, 'OPTIONS', rtrim($probe, '/'));
			if ($preflight !== $catchAll . 'preflight' && !str_starts_with($preflight, 'social.atproto')) {
				$stolen[] = 'OPTIONS ' . rtrim($probe, '/') . ' answered by ' . $preflight;
			}
		}

		$this->assertGreaterThan(300, $checked, 'the walk found the client API');
		$this->assertSame([], $stolen, 'a route the catch-alls took, or a path whose preflight they did not answer');
		$this->assertSame($catchAll . 'notfoundget', $this->routeOf($router, 'GET', '/apps/social/api/v1/no_such_endpoint'));
		$this->assertSame($catchAll . 'notfoundpost', $this->routeOf($router, 'POST', '/apps/social/api/v1/no_such_endpoint'));
	}
}
