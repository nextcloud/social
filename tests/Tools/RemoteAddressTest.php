<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools;

use OCA\Social\Tools\RemoteAddress;
use PHPUnit\Framework\TestCase;

class RemoteAddressTest extends TestCase {
	/**
	 * @dataProvider localIps
	 */
	public function testIsLocalIpForPrivateOrReservedAddresses(string $ip): void {
		$this->assertTrue(RemoteAddress::isLocalIp($ip));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function localIps(): array {
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
		];
	}

	/**
	 * @dataProvider publicHosts
	 */
	public function testIsLocalIpForPublicAddressesAndNonIps(string $host): void {
		$this->assertFalse(RemoteAddress::isLocalIp($host));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function publicHosts(): array {
		return [
			'google dns' => ['8.8.8.8'],
			'cloudflare dns' => ['1.1.1.1'],
			'public v4' => ['93.184.216.34'],
			'public v6' => ['2606:2800:220:1:248:1893:25c8:1946'],
			'not an ip' => ['example.com'],
		];
	}

	/**
	 * @dataProvider localHosts
	 */
	public function testIsLocalHost(string $host): void {
		$this->assertTrue(RemoteAddress::isLocalHost($host));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function localHosts(): array {
		return [
			'loopback literal' => ['127.0.0.1'],
			'bracketed v6 loopback' => ['[::1]'],
			'empty host' => [''],
			'localhost resolves to loopback' => ['localhost'],
		];
	}
}
