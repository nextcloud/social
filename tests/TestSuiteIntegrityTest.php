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
 * Rules about the tests themselves, which no test of the app would catch.
 *
 * A helper named after something PHPUnit already declares is a fatal error, not
 * a failing test: PHP refuses to reduce the visibility of an inherited method
 * while the file is being loaded, so the whole suite dies before a single test
 * runs and the message names only the file. That is how
 * `CheckInstallTest::count()` — a row-counting helper — stopped the entire
 * integration suite from loading. The integration suite runs in CI (every
 * `phpunit-*` workflow runs `composer run test:integration`), but only there:
 * this suite runs everywhere, so the rule is checked here.
 *
 * And a test has to be able to fail: no assertion that holds by construction,
 * and no debugging output left behind in a test.
 */
class TestSuiteIntegrityTest extends TestCase {
	/**
	 * An assertion of a constant, or one comparing an expression with itself.
	 * The second argument may not contain a method call or a `new`: two calls
	 * of the same method can disagree, and saying they do not is a test.
	 */
	private const TAUTOLOGIES = [
		'/assert(?:True\(\s*true|False\(\s*false|Null\(\s*null)\s*[,)]/i',
		'/assert(?:Same|Equals)\(\s*((?:(?!->\w+\(|::\w+\(|new\s)[^,])+?)\s*,\s*\1\s*[,)]/',
	];

	private static function isTautology(string $line): bool {
		foreach (self::TAUTOLOGIES as $tautology) {
			if (preg_match($tautology, $line) === 1) {
				return true;
			}
		}

		return false;
	}

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

	/**
	 * Every line of every PHP file under tests/, but this one.
	 *
	 * @return iterable<string, string> "dir/File.php:line" => the line
	 */
	private function testLines(): iterable {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php' || $file->getRealPath() === __FILE__) {
				continue;
			}

			$name = basename(dirname($file->getPathname())) . '/' . $file->getBasename();
			foreach (file($file->getPathname()) ?: [] as $number => $line) {
				yield $name . ':' . ($number + 1) => $line;
			}
		}
	}

	/** @return string[] file:line => the offending declaration */
	private function narrowedDeclarations(): array {
		$reserved = $this->inheritedPublicMethods();
		$found = [];

		foreach ($this->testLines() as $where => $line) {
			if (preg_match('/^\s*(?:private|protected)\s+(?:static\s+)?function\s+(\w+)\s*\(/', $line, $m) !== 1) {
				continue;
			}

			if (in_array(strtolower($m[1]), $reserved, true)) {
				$found[] = $where . ' declares ' . $m[1] . '()';
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

	/**
	 * Debugging output left in a test prints on every run, and a run that
	 * always prints something hides the run that prints a real warning. The
	 * bootstraps and the coverage floor script are exempt: telling the
	 * developer there is no server to run against, or how a command line is
	 * spelled, is their job.
	 */
	public function testNoTestWritesToStandardError(): void {
		$found = [];
		foreach ($this->testLines() as $where => $line) {
			if (str_contains($line, 'fwrite(' . 'STDERR') && !str_contains($where, '/bootstrap.php:') && !str_contains($where, '/coverage-floor.php:')) {
				$found[] = $where;
			}
		}

		$this->assertSame([], $found, 'debugging output left in a test');
	}

	/** `assertTrue(true)`, and comparing a value with itself, pass whatever the code does. */
	public function testNoAssertionHoldsByConstruction(): void {
		$found = [];
		foreach ($this->testLines() as $where => $line) {
			if (self::isTautology($line)) {
				$found[] = $where . ': ' . trim($line);
			}
		}

		$this->assertSame([], $found, 'an assertion that cannot fail');
	}

	/** @return array<string, array{string, bool}> */
	public static function assertionLines(): array {
		return [
			'true is true' => ['$this->assertTrue(true);', true],
			'a value with itself' => ['$this->assertSame($stats, $stats);', true],
			'a property with itself, with a message' => ["\$this->assertSame(\$this->x['a'], \$this->x['a'], 'same');", true],
			'a function of a value with itself' => ['$this->assertSame(array_keys($by), array_keys($by));', true],
			'two calls of a method' => ["\$this->assertSame(\$this->hasher->hash('tok'), \$this->hasher->hash('tok'));", false],
			'two different values' => ['$this->assertSame($expected, $actual);', false],
			'a real truth' => ['$this->assertTrue($found);', false],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('assertionLines')]
	public function testTheTautologyRuleCatchesWhatItShouldAndNothingElse(string $line, bool $tautology): void {
		$this->assertSame($tautology, self::isTautology($line));
	}

	/**
	 * php.ini's zone is whatever the machine has, and code that reads a
	 * stored or written date in the local zone passes on a UTC laptop and
	 * fails half a day away. The bootstrap pins one zone so a run means the
	 * same thing everywhere.
	 */
	public function testTheSuiteReadsTheClockInUtcUnlessAskedForAnotherZone(): void {
		$asked = getenv('SOCIAL_TEST_TZ');

		$this->assertSame(
			(is_string($asked) && $asked !== '') ? $asked : 'UTC',
			date_default_timezone_get()
		);
	}
}
