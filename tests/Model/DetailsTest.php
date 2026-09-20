<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\Details;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * The `details` blob has a vocabulary, and this is what keeps it having one.
 *
 * Two keys spelled the same way are one key, and a key spelled as a literal at
 * the call site is a key nobody can find: both were how the blob got into the
 * state this class was written to end. Neither is something review reliably
 * catches, so it is asserted instead.
 */
class DetailsTest extends TestCase {
	/** Where a details key may be written as a bare string, and why. */
	private const ALLOWED = [
		// the vocabulary itself
		'lib/Model/Details.php',
	];

	public function testNoTwoNamesForTheSameKey(): void {
		$values = (new ReflectionClass(Details::class))->getConstants();

		$duplicates = array_keys(array_filter(array_count_values($values), static fn (int $seen): bool => $seen > 1));
		$this->assertSame([], $duplicates, 'the same stored key under two names: ' . implode(', ', $duplicates));
	}

	/**
	 * The strings are in the database, not derived from anything, so renaming
	 * one silently orphans every row written before the rename.
	 */
	public function testTheKeysAreLowerCaseAndPlain(): void {
		foreach ((new ReflectionClass(Details::class))->getConstants() as $name => $value) {
			$this->assertMatchesRegularExpression('/^[a-z][a-z_]*$/', (string)$value, $name . ' is not a stored key');
		}
	}

	/**
	 * Every detail key in the app is spelled once, here.
	 *
	 * A literal passed to one of the accessors is the failure mode this class
	 * exists to remove: it cannot be found by searching for the constant, and
	 * a typo in it is a new key rather than an error.
	 */
	public function testNothingSpellsADetailKeyForItself(): void {
		$offenders = [];
		foreach ($this->sources() as $path => $code) {
			if (in_array($path, self::ALLOWED, true)) {
				continue;
			}

			preg_match_all(
				'/(?:set|get|add|remove)Detail(?:s|Item|Array|Bool|Int)?\(\s*\'([a-z_]+)\'/',
				$code,
				$calls
			);
			preg_match_all('/getDetailsAll\(\)\[\s*\'([a-z_]+)\'/', $code, $blobs);

			foreach (array_merge($calls[1], $blobs[1]) as $key) {
				$offenders[] = $path . ": '" . $key . "'";
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"details keys must come from OCA\\Social\\Model\\Details:\n" . implode("\n", $offenders)
		);
	}

	/** @return iterable<string, string> repository path => contents */
	private function sources(): iterable {
		$root = dirname(__DIR__, 2);
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib'));

		foreach ($files as $file) {
			if ($file->isFile() && $file->getExtension() === 'php') {
				yield substr($file->getPathname(), strlen($root) + 1) => (string)file_get_contents($file->getPathname());
			}
		}
	}
}
