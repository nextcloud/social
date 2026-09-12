<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Traits;

use OCA\Social\Tools\Traits\TPathTools;
use PHPUnit\Framework\TestCase;

class TPathToolsTest extends TestCase {
	/** Exposes the protected trait methods. */
	private object $tools;

	protected function setUp(): void {
		$this->tools = new class {
			use TPathTools;

			public function __call(string $name, array $args) {
				return $this->$name(...$args);
			}
		};
	}

	public static function endSlashProvider(): array {
		return [
			'adds a slash' => ['apps/social', 'apps/social/'],
			'keeps a single slash' => ['apps/social/', 'apps/social/'],
			'collapses double slashes' => ['apps//social', 'apps/social/'],
			'trims whitespace' => [' apps/social', 'apps/social/'],
			'empty becomes root' => ['', '/'],
		];
	}

	/**
	 * @dataProvider endSlashProvider
	 */
	public function testWithEndSlash(string $path, string $expected): void {
		$this->assertSame($expected, $this->tools->withEndSlash($path));
	}

	public static function withoutEndSlashProvider(): array {
		return [
			'removes the slash' => ['apps/social/', false, 'apps/social'],
			'removes several' => ['apps/social///', false, 'apps/social'],
			'root is kept' => ['/', false, '/'],
			'root is removed when forced' => ['/', true, ''],
			'no slash to remove' => ['apps/social', false, 'apps/social'],
		];
	}

	/**
	 * @dataProvider withoutEndSlashProvider
	 */
	public function testWithoutEndSlash(string $path, bool $force, string $expected): void {
		$this->assertSame($expected, $this->tools->withoutEndSlash($path, $force));
	}

	public function testWithoutEndSlashCanSkipTheDoubleSlashCleanup(): void {
		$this->assertSame('a//b', $this->tools->withoutEndSlash('a//b/', false, false));
		$this->assertSame('a/b', $this->tools->withoutEndSlash('a//b/'));
	}

	public static function beginSlashProvider(): array {
		return [
			'adds a slash' => ['apps/social', '/apps/social'],
			'keeps a single slash' => ['/apps/social', '/apps/social'],
			'collapses double slashes' => ['/apps//social', '/apps/social'],
			'empty becomes root' => ['', '/'],
		];
	}

	/**
	 * @dataProvider beginSlashProvider
	 */
	public function testWithBeginSlash(string $path, string $expected): void {
		$this->assertSame($expected, $this->tools->withBeginSlash($path));
	}

	public static function withoutBeginSlashProvider(): array {
		return [
			'removes the slash' => ['/apps/social', false, 'apps/social'],
			'removes several' => ['///apps/social', false, 'apps/social'],
			'root is kept' => ['/', false, '/'],
			'root is removed when forced' => ['/', true, ''],
			'no slash to remove' => ['apps/social', false, 'apps/social'],
		];
	}

	/**
	 * @dataProvider withoutBeginSlashProvider
	 */
	public function testWithoutBeginSlash(string $path, bool $force, string $expected): void {
		$this->assertSame($expected, $this->tools->withoutBeginSlash($path, $force));
	}

	public function testWithoutBeginAtStripsTheMentionPrefix(): void {
		$this->assertSame('alice@mastodon.social', $this->tools->withoutBeginAt('@alice@mastodon.social'));
		$this->assertSame('alice@mastodon.social', $this->tools->withoutBeginAt('@@alice@mastodon.social '));
		$this->assertSame('alice', $this->tools->withoutBeginAt('alice'));
	}
}
