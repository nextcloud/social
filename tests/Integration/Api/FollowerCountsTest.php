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
use OC\Security\CSRF\CsrfTokenManager;
use OCA\Social\AppInfo\Application;
use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\StatusApiController;
use OCA\Social\Service\CountsService;
use OCA\Social\Service\IRecommendationSignalEvents;
use OCA\Social\Service\IRecommendationSignalRecorder;
use OCA\Social\Service\RecommendationSignalService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\Server;

/**
 * A reader who hides the numbers is still sent an account's real follower
 * count — their own and anybody else's — while a status's numbers are taken
 * out, through the real dispatcher and every middleware the app registers.
 * A client told 0 shows "0 followers" as a fact, and the profile page,
 * Statistics and the followers list all count the same people.
 */
class FollowerCountsTest extends ApiTestCase {
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
	 * @param array<string, string> $route
	 */
	private function dispatch(string $class, string $method, string $path, string $as, array $route = []): \stdClass {
		$request = new Request(
			[
				'server' => [
					'REQUEST_METHOD' => 'GET',
					'REQUEST_URI' => '/index.php' . $path,
					'SCRIPT_NAME' => '/index.php',
					'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($as),
				],
				'method' => 'GET',
				'urlParams' => array_merge($route, ['_route' => 'social.counts.' . $method]),
			],
			Server::get(IRequestId::class),
			Server::get(IConfig::class),
			Server::get(CsrfTokenManager::class),
		);

		$container = new DIContainer('social');
		$container->registerAlias(IRecommendationSignalEvents::class, RecommendationSignalService::class);
		$container->registerAlias(IRecommendationSignalRecorder::class, RecommendationSignalService::class);
		$container->registerService(IRequest::class, static fn (): IRequest => $request);
		foreach ($this->appMiddlewares() as $middleware) {
			$container->registerMiddleWare($middleware);
		}

		/** @var Response $response */
		$response = $container->get(Dispatcher::class)->dispatch($container->get($class), $method)[4];
		$answer = new ApiResponse($response);
		$this->assertSame(200, $answer->status, $method . ': ' . $answer->raw);
		$this->assertInstanceOf(\stdClass::class, $answer->body);

		return $answer->body;
	}

	/**
	 * The counters of an account are kept on its cached row and moved by the
	 * follows as they happen. A new account's row used to start at "never
	 * counted", which no follow moves, so its profile said 0 until the cron's
	 * walk counted it.
	 */
	public function testANewAccountsFollowersAreCountedFromItsFirstFollow(): void {
		$this->follow('bob', 'alice');
		$this->follow('carol', 'alice');

		$me = $this->call(AccountApiController::class, 'verifyCredentials', [], [], 'alice');
		$this->assertSame(200, $me->status);
		$this->assertSame(2, $me->body->followers_count);
		$this->assertSame(0, $me->body->following_count);

		$bobId = (string)$this->account('bob')->getNid();
		$bob = $this->call(AccountApiController::class, 'accountGet', ['id' => $bobId], [], 'alice');
		$this->assertSame(0, $bob->body->followers_count);
		$this->assertSame(1, $bob->body->following_count);
	}

	public function testAReaderWhoHidesTheNumbersIsSentTheRealFollowerCounts(): void {
		$this->follow('bob', 'alice');
		$this->follow('carol', 'alice');
		$this->follow('alice', 'bob');
		$post = $this->publish('alice', ['status' => 'counted ' . bin2hex(random_bytes(4)), 'visibility' => 'public']);
		$this->act('bob', $post->id, 'favourite');
		$counts = Server::get(CountsService::class);
		$counts->setHides(self::PREFIX . 'alice', true);
		$this->assertTrue($counts->hides(self::PREFIX . 'alice'));

		$me = $this->dispatch(AccountApiController::class, 'verifyCredentials', '/apps/social/api/v1/accounts/verify_credentials', 'alice');
		$this->assertSame(2, $me->followers_count, 'the owner\'s own followers');
		$this->assertSame(1, $me->following_count);

		$bobId = (string)$this->account('bob')->getNid();
		$bob = $this->dispatch(AccountApiController::class, 'accountGet', '/apps/social/api/v1/accounts/' . $bobId, 'alice', ['id' => $bobId]);
		$this->assertSame(1, $bob->followers_count, 'somebody else\'s followers');

		$status = $this->dispatch(StatusApiController::class, 'statusGet', '/apps/social/api/v1/statuses/' . $post->id, 'alice', ['nid' => $post->id]);
		$this->assertSame(0, $status->favourites_count, 'a status\'s numbers are what the reader asked not to be shown');
		$this->assertSame(2, $status->account->followers_count, 'the author on the status, too');
	}
}
