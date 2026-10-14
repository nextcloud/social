<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use OCA\Social\Tests\Interop\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The interop suites' decision about a missing variable: skip on a
 * developer's machine, fail in CI, where the workflow sets every variable
 * its job needs and a skip would pass a broken job.
 */
class InteropEnvironmentTest extends TestCase {
	public function testEverythingSetIsHandedBackWhereverItRuns(): void {
		$values = ['MASTODON_BASE_URL' => 'https://mastodon.test', 'MASTODON_TOKEN' => 'abc'];

		$this->assertSame($values, Environment::check(false, 'Mastodon', $values));
		$this->assertSame($values, Environment::check(true, 'Mastodon', $values));
	}

	public function testAMissingVariableIsNullOnADeveloperMachine(): void {
		$this->assertNull(Environment::check(false, 'Mastodon', ['MASTODON_BASE_URL' => 'https://mastodon.test', 'MASTODON_TOKEN' => '']));
	}

	public function testAMissingVariableFailsInCiNamingIt(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no Mastodon to talk to: MASTODON_TOKEN, MASTODON_TOKEN_STRANGER not set, and this is CI');

		Environment::check(true, 'Mastodon', [
			'MASTODON_BASE_URL' => 'https://mastodon.test',
			'MASTODON_TOKEN' => '',
			'MASTODON_TOKEN_STRANGER' => '',
		]);
	}

	public function testAFileThatIsThereIsItsPath(): void {
		$this->assertSame(__FILE__, Environment::checkFile(true, 'Bluesky development network', 'ATPROTO_NETWORK_FILE', __FILE__));
		$this->assertSame(__FILE__, Environment::checkFile(false, 'Bluesky development network', 'ATPROTO_NETWORK_FILE', __FILE__));
	}

	public function testAFileThatIsNotThereIsNullLocallyAndAFailureInCi(): void {
		$path = __DIR__ . '/no-such-network.json';
		$this->assertNull(Environment::checkFile(false, 'Bluesky development network', 'ATPROTO_NETWORK_FILE', $path));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ATPROTO_NETWORK_FILE names ' . $path . ', which is not a file, and this is CI');
		Environment::checkFile(true, 'Bluesky development network', 'ATPROTO_NETWORK_FILE', $path);
	}

	/** @return iterable<string, array{string|false, bool}> */
	public static function ciSpellings(): iterable {
		yield 'unset' => [false, false];
		yield 'GitHub Actions' => ['true', true];
		yield 'the brief\'s CI=1' => ['1', true];
		yield 'any other word' => ['yes', true];
		yield 'empty' => ['', false];
		yield 'zero' => ['0', false];
		yield 'false' => ['false', false];
		yield 'FALSE with spaces' => [' FALSE ', false];
		yield 'no' => ['no', false];
	}

	#[DataProvider('ciSpellings')]
	public function testTheCiVariableIsReadTheWayPeopleSetIt(string|false $value, bool $isCi): void {
		$this->assertSame($isCi, Environment::isCi($value));
	}
}
