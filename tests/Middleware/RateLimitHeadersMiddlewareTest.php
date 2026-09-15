<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Middleware;

use OCA\Social\Middleware\RateLimitHeadersMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A controller with one route of each kind, for the reflection to read.
 */
class RateLimitedController extends Controller {
	#[UserRateLimit(limit: 60, period: 60)]
	public function limited(): DataResponse {
		return new DataResponse([]);
	}

	#[UserRateLimit(limit: 10, period: 60)]
	#[AnonRateLimit(limit: 3, period: 60)]
	public function both(): DataResponse {
		return new DataResponse([]);
	}

	public function unlimited(): DataResponse {
		return new DataResponse([]);
	}
}

/**
 * What a client is told about its budget, and what it is not told.
 */
class RateLimitHeadersMiddlewareTest extends TestCase {
	private IUserSession|MockObject $userSession;
	private array $store = [];
	private RateLimitHeadersMiddleware $middleware;
	private RateLimitedController $controller;

	protected function tearDown(): void {
		\OC::$server->reset();
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.7');
		$request->method('getId')->willReturn('test-request');
		// Response::getHeaders() resolves IRequest out of the container for
		// the X-Request-Id it merges in
		\OC::$server->register(IRequest::class, $request);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $k) => $this->store[$k] ?? null);
		$cache->method('set')->willReturnCallback(
			function (string $k, $v): bool {
				$this->store[$k] = $v;

				return true;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new RateLimitedController('social', $request);

		$this->middleware = new RateLimitHeadersMiddleware(
			$request, $this->userSession, $cacheFactory
		);
	}

	private function signedInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function call(string $method): DataResponse {
		/** @var DataResponse $response */
		$response = $this->middleware->afterController(
			$this->controller, $method, new DataResponse([])
		);

		return $response;
	}

	public function testTheBudgetOnTheRouteIsWhatIsReported(): void {
		$this->signedInAs('alice');

		$headers = $this->call('limited')->getHeaders();

		$this->assertSame('60', $headers['X-RateLimit-Limit']);
		$this->assertSame('59', $headers['X-RateLimit-Remaining'], 'this request is one of them');
	}

	/** The point of the header: a client can see the budget going down. */
	public function testEachRequestSpendsOneOfThem(): void {
		$this->signedInAs('alice');

		$this->call('limited');
		$this->call('limited');
		$headers = $this->call('limited')->getHeaders();

		$this->assertSame('57', $headers['X-RateLimit-Remaining']);
	}

	/** One account's spending is not another's. */
	public function testTwoAccountsHaveTwoBudgets(): void {
		$user = $this->createMock(IUser::class);
		$uid = 'alice';
		$user->method('getUID')->willReturnCallback(static function () use (&$uid): string {
			return $uid;
		});
		$this->userSession->method('getUser')->willReturn($user);

		$this->call('limited');
		$this->call('limited');

		$uid = 'bob';
		$headers = $this->call('limited')->getHeaders();

		$this->assertSame('59', $headers['X-RateLimit-Remaining']);
	}

	/** And neither is one route's the other's. */
	public function testTwoRoutesHaveTwoBudgets(): void {
		$this->signedInAs('alice');

		$this->call('limited');
		$headers = $this->call('both')->getHeaders();

		$this->assertSame('10', $headers['X-RateLimit-Limit']);
		$this->assertSame('9', $headers['X-RateLimit-Remaining']);
	}

	/**
	 * Where a route carries both, the one that applies is the one Nextcloud
	 * would enforce for this caller — otherwise the header and the refusal
	 * would be describing different budgets.
	 */
	public function testAnAnonymousCallerIsToldTheAnonymousBudget(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$headers = $this->call('both')->getHeaders();

		$this->assertSame('3', $headers['X-RateLimit-Limit']);
	}

	/**
	 * "Unlimited" and "I did not measure" are different answers, and a client
	 * should be able to tell them apart.
	 */
	public function testARouteWithNoLimitIsGivenNoHeaders(): void {
		$this->signedInAs('alice');

		$headers = $this->call('unlimited')->getHeaders();

		$this->assertArrayNotHasKey('X-RateLimit-Limit', $headers);
		$this->assertArrayNotHasKey('X-RateLimit-Remaining', $headers);
	}

	/** The reset is when the window ends, in seconds since the epoch. */
	public function testTheResetIsTheEndOfTheWindowThisRequestFellIn(): void {
		$this->signedInAs('alice');

		$reset = (int)$this->call('limited')->getHeaders()['X-RateLimit-Reset'];

		$this->assertGreaterThan(time(), $reset);
		$this->assertLessThanOrEqual(time() + 60, $reset);
		$this->assertSame(0, $reset % 60, 'the window boundary, not "now plus a minute"');
	}

	/** Remaining never goes below zero, whatever the counter says. */
	public function testTheRemainingBudgetIsNeverNegative(): void {
		$this->signedInAs('alice');

		for ($i = 0; $i < 12; $i++) {
			$headers = $this->call('both')->getHeaders();
		}

		$this->assertSame('0', $headers['X-RateLimit-Remaining']);
	}
}
