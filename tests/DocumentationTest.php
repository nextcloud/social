<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Keeps `docs/` mechanically in sync with the code.
 *
 * Everything here is parsed out of the source files with regular expressions;
 * nothing is instantiated, because the classes involved (commands, in
 * particular) extend server-internal base classes that are not autoloadable in
 * the standalone test suite.
 *
 * Only contracts that a reader of the docs would act on are asserted: occ
 * command names, HTTP route URLs, supported version ranges and the app
 * version. Counts of internal classes are deliberately not asserted.
 */
class DocumentationTest extends TestCase {
	/** Endpoints the app serves outside `appinfo/routes.php`, so the doc may name them. */
	private const NON_ROUTE_ENDPOINTS = [
		// Registered as an IHandler in lib/WellKnown/WebfingerHandler.php, not as a route.
		'/.well-known/webfinger',
	];

	public function testInfoXmlRegistersEveryCommandClass(): void {
		$onDisk = $this->commandClassesOnDisk();
		$registered = $this->registeredCommandClasses();

		$this->assertSame(
			[],
			array_values(array_diff($onDisk, $registered)),
			'These command classes exist in lib/Command/ but are not registered:'
			. ' add a <command> entry for each in appinfo/info.xml.'
		);
		$this->assertSame(
			[],
			array_values(array_diff($registered, $onDisk)),
			'These <command> entries in appinfo/info.xml have no class in lib/Command/:'
			. ' remove them from appinfo/info.xml or add the missing class.'
		);
	}

	public function testEveryRegisteredCommandIsDocumented(): void {
		$registered = $this->registeredCommandNames();
		$documented = $this->documentedCommandNames();

		$this->assertSame(
			[],
			array_values(array_diff($registered, $documented)),
			'These occ commands are registered but undocumented:'
			. ' document them in docs/OCC-Commands.md.'
		);
		$this->assertSame(
			[],
			array_values(array_diff($documented, $registered)),
			'These occ commands are documented in docs/OCC-Commands.md but not registered:'
			. ' remove them from docs/OCC-Commands.md, or register them in appinfo/info.xml.'
		);
	}

	public function testEveryRouteIsDocumented(): void {
		$routes = $this->routeUrls();
		$documented = $this->documentedPaths();

		$this->assertSame(
			[],
			array_values(array_diff($routes, $documented)),
			'These routes from appinfo/routes.php are undocumented:'
			. ' document these routes in docs/API.md (keep the {placeholder} names verbatim).'
		);
	}

	public function testDocumentedPathsAreRealRoutes(): void {
		$routes = $this->routeUrls();
		$documented = $this->documentedPaths();

		$this->assertSame(
			[],
			array_values(array_diff($documented, $routes)),
			'These paths in docs/API.md are not routes:'
			. ' remove them from docs/API.md, or fix the path (a wrong {placeholder} name'
			. ' counts as a wrong path) so it matches a url in appinfo/routes.php.'
		);
	}

	public function testSupportedNextcloudVersionsAreDocumented(): void {
		$doc = $this->read('docs/Architecture.md');

		$this->assertSame(
			$this->dependencyVersion('nextcloud', 'min'),
			$this->documentedVersion($doc, 'nextcloud', 'min'),
			'docs/Architecture.md states the wrong minimum Nextcloud version:'
			. ' state the <nextcloud min-version> from appinfo/info.xml.'
		);
		$this->assertSame(
			$this->dependencyVersion('nextcloud', 'max'),
			$this->documentedVersion($doc, 'nextcloud', 'max'),
			'docs/Architecture.md states the wrong maximum Nextcloud version:'
			. ' state the <nextcloud max-version> from appinfo/info.xml.'
		);
	}

	public function testSupportedPhpVersionsAreDocumented(): void {
		$doc = $this->read('docs/Architecture.md');

		$this->assertSame(
			$this->dependencyVersion('php', 'min'),
			$this->documentedVersion($doc, 'php', 'min'),
			'docs/Architecture.md states the wrong minimum PHP version:'
			. ' state the <php min-version> from appinfo/info.xml.'
		);
		$this->assertSame(
			$this->dependencyVersion('php', 'max'),
			$this->documentedVersion($doc, 'php', 'max'),
			'docs/Architecture.md states the wrong maximum PHP version:'
			. ' state the <php max-version> from appinfo/info.xml.'
		);
	}

	public function testAppVersionMatchesComposerVersion(): void {
		$composer = json_decode($this->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);
		if (!isset($composer['version'])) {
			$this->addToAssertionCount(1);

			return;
		}

		$this->assertSame(
			$this->appVersion(),
			(string)$composer['version'],
			'The version in composer.json disagrees with <version> in appinfo/info.xml:'
			. ' bump "version" in composer.json to the appinfo/info.xml version'
			. ' (appinfo/info.xml is the release version of the app).'
		);
	}

	private function read(string $relativePath): string {
		$path = __DIR__ . '/../' . $relativePath;
		$content = file_get_contents($path);
		$this->assertIsString($content, $relativePath . ' is missing or unreadable.');

		return $content;
	}

	/** Fully qualified command classes listed as `<command>` in appinfo/info.xml, sorted. */
	private function registeredCommandClasses(): array {
		preg_match_all(
			'/<command>\s*([^<\s]+)\s*<\/command>/',
			$this->read('appinfo/info.xml'),
			$matches
		);

		return $this->normalise($matches[1]);
	}

	/**
	 * Fully qualified command classes found in lib/Command/, sorted.
	 *
	 * Files that register no command name (the shared ExtendedBase) are not
	 * commands and are skipped.
	 */
	private function commandClassesOnDisk(): array {
		$classes = [];
		foreach ($this->commandFiles() as $file => $code) {
			if ($this->commandNameOf($code) === null) {
				continue;
			}

			$classes[] = 'OCA\\Social\\Command\\' . basename($file, '.php');
		}

		return $this->normalise($classes);
	}

	/** Command names passed to setName() in lib/Command/, sorted. */
	private function registeredCommandNames(): array {
		$names = [];
		foreach ($this->commandFiles() as $code) {
			$name = $this->commandNameOf($code);
			if ($name !== null) {
				$names[] = $name;
			}
		}

		return $this->normalise($names);
	}

	/**
	 * Command names that have their own section in docs/OCC-Commands.md, sorted.
	 *
	 * A heading is what documents a command; a name in prose may well be a
	 * counter-example ("the command is `social:details`, not `social:stream:details`").
	 */
	private function documentedCommandNames(): array {
		preg_match_all(
			'/^#{2,4}[^\S\n]+`(social:[a-z0-9:_-]+)`/m',
			$this->read('docs/OCC-Commands.md'),
			$matches
		);

		return $this->normalise($matches[1]);
	}

	/** @return array<string, string> file path => file contents */
	private function commandFiles(): array {
		$files = glob(__DIR__ . '/../lib/Command/*.php');
		$this->assertNotEmpty($files, 'No command classes found in lib/Command/.');

		$contents = [];
		foreach ($files as $file) {
			$contents[$file] = (string)file_get_contents($file);
		}

		return $contents;
	}

	private function commandNameOf(string $code): ?string {
		if (preg_match('/setName\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $code, $match) !== 1) {
			return null;
		}

		return $match[1];
	}

	/** Route urls declared in appinfo/routes.php, normalised and sorted. */
	private function routeUrls(): array {
		$definition = require __DIR__ . '/../appinfo/routes.php';
		$this->assertArrayHasKey('routes', $definition, 'appinfo/routes.php declares no routes.');

		$urls = [];
		foreach ($definition['routes'] as $route) {
			$this->assertArrayHasKey('url', $route, 'A route in appinfo/routes.php has no url.');
			$urls[] = $this->normalisePath((string)$route['url']);
		}

		return $this->normalise($urls);
	}

	/**
	 * Paths documented in docs/API.md, normalised and sorted.
	 *
	 * Only code spans inside the endpoint tables count: paths in prose are
	 * illustrations (a base URL, a `/api/v1/` prefix, a full https:// example)
	 * rather than claims about a route.
	 */
	private function documentedPaths(): array {
		$paths = [];
		foreach (explode("\n", $this->read('docs/API.md')) as $line) {
			if (!str_starts_with(trim($line), '|')) {
				continue;
			}

			preg_match_all('/`(\/[^`]*)`/', $line, $matches);
			foreach ($matches[1] as $path) {
				if (in_array($path, self::NON_ROUTE_ENDPOINTS, true)) {
					continue;
				}

				$paths[] = $this->normalisePath($path);
			}
		}

		return $this->normalise($paths);
	}

	/** Drops a trailing slash, so `/local/` and `/local` compare equal; keeps the root. */
	private function normalisePath(string $path): string {
		$trimmed = rtrim(trim($path), '/');

		return $trimmed === '' ? '/' : $trimmed;
	}

	private function appVersion(): string {
		$found = preg_match(
			'/<version>\s*([^<\s]+)\s*<\/version>/',
			$this->read('appinfo/info.xml'),
			$match
		);
		$this->assertSame(1, $found, 'appinfo/info.xml declares no <version>.');

		return $match[1];
	}

	/**
	 * A `min-version`/`max-version` from the `<dependencies>` of appinfo/info.xml.
	 *
	 * @param string $subject `nextcloud` or `php`
	 * @param string $bound `min` or `max`
	 */
	private function dependencyVersion(string $subject, string $bound): string {
		$found = preg_match(
			'/<' . $subject . '\b[^>]*\b' . $bound . '-version="([^"]+)"/',
			$this->read('appinfo/info.xml'),
			$match
		);
		$this->assertSame(
			1,
			$found,
			'appinfo/info.xml declares no ' . $subject . ' ' . $bound . '-version.'
		);

		return $match[1];
	}

	/**
	 * The version a doc states for one bound of one dependency.
	 *
	 * Accepts the labelled forms ("Minimum NC version: 28", "PHP max version: 8.5")
	 * in either word order, and a range ("Nextcloud: 28 - 35").
	 *
	 * @param string $subject `nextcloud` or `php`
	 * @param string $bound `min` or `max`
	 */
	private function documentedVersion(string $doc, string $subject, string $bound): string {
		$name = $subject === 'nextcloud' ? '(?:nextcloud|nc)' : 'php';
		$limit = $bound === 'min' ? '(?:minimum|min)\.?' : '(?:maximum|max)\.?';
		$version = '`?([0-9]+(?:\.[0-9]+)*)`?';
		$gap = '[\s*_:]+';

		$patterns = [
			'/' . $limit . $gap . $name . $gap . '(?:version[s]?' . $gap . ')?' . $version . '/i',
			'/' . $name . $gap . $limit . $gap . '(?:version[s]?' . $gap . ')?' . $version . '/i',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $doc, $match) === 1) {
				return $match[1];
			}
		}

		$range = '/' . $name . $gap . '(?:version[s]?' . $gap . ')?' . $version
			. '\s*(?:-|--|–|—|\.\.\.?|to)\s*' . $version . '/i';
		if (preg_match($range, $doc, $match) === 1) {
			return $bound === 'min' ? $match[1] : $match[2];
		}

		return 'not stated';
	}

	/**
	 * @param string[] $values
	 * @return string[]
	 */
	private function normalise(array $values): array {
		$unique = array_unique($values);
		sort($unique);

		return array_values($unique);
	}
}
