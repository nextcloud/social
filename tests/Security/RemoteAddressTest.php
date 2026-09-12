<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Security;

use OCA\Social\Security\RemoteAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RemoteAddressTest extends TestCase {
	#[DataProvider('localIps')]
	public function testIsLocalIpForPrivateOrReservedAddresses(string $ip): void {
		$this->assertTrue(RemoteAddress::isLocalIp($ip));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function localIps(): array {
		return [
			'loopback v4' => ['127.0.0.1'],
			'private 10/8' => ['10.0.0.1'],
			'private 172.16/12 low' => ['172.16.0.1'],
			'private 172.16/12 high' => ['172.31.255.255'],
			'private 192.168/16' => ['192.168.1.1'],
			'cloud metadata link-local' => ['169.254.169.254'],
			'unspecified 0.0.0.0' => ['0.0.0.0'],
			'loopback v6' => ['::1'],
			'link-local v6' => ['fe80::1'],
			'unique local v6' => ['fc00::1'],
			'multicast v6' => ['ff02::1'],
			'multicast v4' => ['224.0.0.1'],
			'v4-mapped loopback' => ['::ffff:127.0.0.1'],
			'v4-mapped private' => ['::ffff:10.0.0.1'],
			'v4-mapped metadata' => ['::ffff:169.254.169.254'],
			'v4-mapped long form' => ['0:0:0:0:0:ffff:7f00:1'],
			'NAT64-embedded loopback' => ['64:ff9b::7f00:1'],
			'NAT64-embedded private' => ['64:ff9b::a00:1'],
		];
	}

	#[DataProvider('publicHosts')]
	public function testIsLocalIpForPublicAddressesAndNonIps(string $host): void {
		$this->assertFalse(RemoteAddress::isLocalIp($host));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function publicHosts(): array {
		return [
			'google dns' => ['8.8.8.8'],
			'cloudflare dns' => ['1.1.1.1'],
			'public v4' => ['93.184.216.34'],
			'public v6' => ['2606:2800:220:1:248:1893:25c8:1946'],
			'NAT64-embedded public' => ['64:ff9b::808:808'],
			'not an ip' => ['example.com'],
		];
	}

	#[DataProvider('localHosts')]
	public function testIsLocalHost(string $host): void {
		$this->assertTrue(RemoteAddress::isLocalHost($host));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function localHosts(): array {
		return [
			'loopback literal' => ['127.0.0.1'],
			'bracketed v6 loopback' => ['[::1]'],
			'empty host' => [''],
			'localhost resolves to loopback' => ['localhost'],
		];
	}

	// --- the memo

	public function testAHostIsResolvedOncePerProcess(): void {
		RemoteAddress::forgetResolved();

		// a name that cannot resolve is refused; asking again must give the
		// same answer without going back to the resolver
		$first = RemoteAddress::isLocalHost('nothing.invalid');
		$second = RemoteAddress::isLocalHost('nothing.invalid');

		$this->assertTrue($first);
		$this->assertSame($first, $second);
	}

	public function testTheMemoIsPerHost(): void {
		RemoteAddress::forgetResolved();

		// a literal address never reaches the resolver at all, so the memo
		// cannot confuse the two
		$this->assertTrue(RemoteAddress::isLocalHost('127.0.0.1'));
		$this->assertTrue(RemoteAddress::isLocalHost('nothing.invalid'));
		$this->assertFalse(RemoteAddress::isLocalHost('203.0.113.10'));
	}

	public function testForgettingTheMemoIsSafeWhenEmpty(): void {
		RemoteAddress::forgetResolved();
		RemoteAddress::forgetResolved();

		$this->assertTrue(RemoteAddress::isLocalHost('nothing.invalid'));
	}
}
