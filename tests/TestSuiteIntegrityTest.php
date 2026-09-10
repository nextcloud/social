<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A helper named after something PHPUnit already declares is a fatal error, not
 * a failing test: PHP refuses to reduce the visibility of an inherited method
 * while the file is being loaded, so the whole suite dies before a single test
 * runs and the message names only the file.
 *
 * That is how `CheckInstallTest::count()` — a row-counting helper — stopped the
 * entire integration suite from loading. Nothing noticed, because the
 * integration suite needs a server and does not run in CI.
 */
class TestSuiteIntegrityTest extends TestCase {
	/** @return string[] */
	private function inheritedPublicMethods(): array {
		$names = [];
		foreach ((new ReflectionClass(TestCase::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if (!$method->isStatic()) {
				$names[] = strtolower($method->getName());
			}
		}

		return $names;
	}

	/** @return string[] file:line => the offending declaration */
	private function narrowedDeclarations(): array {
		$reserved = $this->inheritedPublicMethods();
		$found = [];

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$lines = file($file->getPathname()) ?: [];
			foreach ($lines as $number => $line) {
				if (preg_match('/^\s*(?:private|protected)\s+(?:static\s+)?function\s+(\w+)\s*\(/', $line, $m) !== 1) {
					continue;
				}

				if (in_array(strtolower($m[1]), $reserved, true)) {
					$found[] = basename(dirname($file->getPathname())) . '/' . $file->getBasename()
						. ':' . ($number + 1) . ' declares ' . $m[1] . '()';
				}
			}
		}

		return $found;
	}

	public function testNoTestNarrowsAMethodPhpunitDeclaresPublic(): void {
		$this->assertSame(
			[],
			$this->narrowedDeclarations(),
			'a helper named after a TestCase method is a load-time fatal, and it takes the whole suite with it'
		);
	}
}
