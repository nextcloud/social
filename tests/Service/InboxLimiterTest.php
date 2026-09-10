<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\TooManyRequestsException;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InboxLimiter;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InboxLimiterTest extends TestCase {
	private ConfigService|MockObject $configService;
	private InboxLimiter $limiter;
	/** @var array<string, mixed> the distributed cache, simulated */
	private array $cache = [];

	protected function setUp(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->cache[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value) {
			$this->cache[$key] = $value;

			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->with('social.inbox')->willReturn($cache);

		$this->configService = $this->createMock(ConfigService::class);
		$this->limiter = new InboxLimiter($cacheFactory, $this->configService);
	}

	private function limit(int $limit): void {
		$this->configService->method('getAppValue')
			->with(ConfigService::SOCIAL_INBOX_THROTTLE)->willReturn((string)$limit);
	}

	/** @return IRequest&MockObject */
	private function request(string $keyId, string $ip = '198.51.100.7'): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Signature')->willReturn(
			$keyId === '' ? '' : 'keyId="' . $keyId . '",algorithm="rsa-sha256",signature="…"'
		);
		$request->method('getRemoteAddress')->willReturn($ip);

		return $request;
	}

	public function testRequestsUnderTheLimitPass(): void {
		$this->limit(3);
		$request = $this->request('https://remote.example/users/bob#main-key');

		$this->limiter->assertAllowed($request);
		$this->limiter->assertAllowed($request);
		$this->limiter->assertAllowed($request);
		$this->addToAssertionCount(1);
	}

	public function testTheRequestOverTheLimitIsRefused(): void {
		$this->limit(2);
		$request = $this->request('https://remote.example/users/bob#main-key');
		$this->limiter->assertAllowed($request);
		$this->limiter->assertAllowed($request);

		$this->expectException(TooManyRequestsException::class);

		$this->limiter->assertAllowed($request);
	}

	public function testRotatingTheClaimedHostDoesNotMintAFreshBucket(): void {
		// the keyId is unverified at this point, so a flood that writes a new
		// hostname on every request must still land in the same bucket
		$this->limit(2);
		$this->limiter->assertAllowed($this->request('https://one.example/actor#main-key'));
		$this->limiter->assertAllowed($this->request('https://two.example/actor#main-key'));

		$this->expectException(TooManyRequestsException::class);

		$this->limiter->assertAllowed($this->request('https://three.example/actor#main-key'));
	}

	public function testAClaimedHostIsAlsoCappedAcrossAddresses(): void {
		// one origin delivering from a fleet of addresses is bounded too, at a
		// looser ceiling than a single address gets
		$this->limit(1);
		$allowed = InboxLimiter::HOST_LIMIT_FACTOR;
		for ($i = 0; $i < $allowed; $i++) {
			$this->limiter->assertAllowed(
				$this->request('https://one.example/actor#main-key', '198.51.100.' . $i)
			);
		}

		$this->expectException(TooManyRequestsException::class);

		$this->limiter->assertAllowed(
			$this->request('https://one.example/actor#main-key', '198.51.100.200')
		);
	}

	public function testDifferentSourceAddressesUseDifferentBuckets(): void {
		// the per-address ceiling is per address: one noisy peer does not spend
		// another's budget
		$this->limit(1);
		$this->limiter->assertAllowed($this->request('https://one.example/a#k', '198.51.100.7'));
		$this->limiter->assertAllowed($this->request('https://two.example/a#k', '203.0.113.9'));
		$this->addToAssertionCount(1);
	}

	public function testAMissingSignatureHeaderFallsBackToTheAddressBucket(): void {
		$this->limit(1);
		$this->limiter->assertAllowed($this->request(''));

		$this->expectException(TooManyRequestsException::class);

		$this->limiter->assertAllowed($this->request(''));
	}

	public function testZeroDisablesTheLimiter(): void {
		$this->limit(0);
		$request = $this->request('https://remote.example/users/bob#main-key');

		for ($i = 0; $i < 50; $i++) {
			$this->limiter->assertAllowed($request);
		}
		$this->assertSame([], $this->cache, 'disabled limiter never touches the cache');
	}
}
