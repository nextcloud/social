<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use RuntimeException;

/**
 * The environment variables an interop suite needs, and what their absence
 * means.
 *
 * On a developer's machine a missing variable means there is no Mastodon (or
 * PeerTube, Loops, a Bluesky network, …) to talk to, and the tests skip with
 * a reason. In CI the workflow sets every variable its job needs, so a
 * missing one is a broken job, and a skip there is a green run that asserted
 * nothing: the suite fails instead, naming the variable. CI is told apart by
 * the `CI` variable, which GitHub Actions sets to `true`.
 */
final class Environment {
	/**
	 * The values of `$names`, or null when one is unset and this is not CI.
	 *
	 * @return array<string, string>|null variable name => value
	 * @throws RuntimeException in CI, naming what is unset
	 */
	public static function values(string $what, string ...$names): ?array {
		$values = [];
		foreach ($names as $name) {
			$values[$name] = (string)getenv($name);
		}

		return self::check(self::isCi(getenv('CI')), $what, $values);
	}

	/**
	 * A variable that names a file the job made (the Bluesky network's
	 * addresses, a sample video): its path when it is set and the file is
	 * there, null otherwise, and in CI the exception instead of null.
	 *
	 * @throws RuntimeException in CI, when the variable is unset or the file is not there
	 */
	public static function file(string $what, string $name): ?string {
		$values = self::values($what, $name);
		if ($values === null) {
			return null;
		}

		return self::checkFile(self::isCi(getenv('CI')), $what, $name, $values[$name]);
	}

	/**
	 * The decision behind `values()`: the values when every one is set, null
	 * when one is not and this is a local run, an exception when one is not
	 * and this is CI.
	 *
	 * @param array<string, string> $values variable name => value
	 * @return array<string, string>|null
	 * @throws RuntimeException
	 */
	public static function check(bool $ci, string $what, array $values): ?array {
		$missing = array_keys(array_filter($values, static fn (string $value): bool => $value === ''));
		if ($missing === []) {
			return $values;
		}
		if ($ci) {
			throw new RuntimeException(
				'no ' . $what . ' to talk to: ' . implode(', ', $missing) . ' not set, and this is CI,'
				. ' where the workflow sets it; the suite fails rather than skips so that a broken job cannot pass'
			);
		}

		return null;
	}

	/**
	 * The decision behind `file()`, once the variable is known to be set.
	 *
	 * @throws RuntimeException
	 */
	public static function checkFile(bool $ci, string $what, string $name, string $path): ?string {
		if (is_file($path)) {
			return $path;
		}
		if ($ci) {
			throw new RuntimeException(
				'no ' . $what . ' to talk to: ' . $name . ' names ' . $path . ', which is not a file,'
				. ' and this is CI, where the workflow makes it; the suite fails rather than skips so that a broken job cannot pass'
			);
		}

		return null;
	}

	/**
	 * Whether `$ci`, the `CI` variable as `getenv()` hands it over, says this
	 * is a CI run: set, and not one of the spellings of "no".
	 */
	public static function isCi(string|false $ci): bool {
		if ($ci === false) {
			return false;
		}

		return !in_array(strtolower(trim($ci)), ['', '0', 'false', 'no', 'off'], true);
	}
}
