<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AppInfo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the web server may hand out of the app's own directory, and how a
 * controller may end a request.
 *
 * An app directory sits under the web root, and on Apache nothing but a
 * `.htaccess` keeps a request for `apps/social/src/…` or
 * `apps/social/appinfo/…` from being answered with the file. The root and
 * `templates/` must not carry one — that blocked the app's own scripts and
 * images — so the deny files live in the directories that hold nothing to
 * serve.
 */
class ServedTreeTest extends TestCase {
	/** @var array<string, string> controllers that may end a request with exit(), and why */
	private const HARD_EXITS = [
		'QueueController.php' => 'the async delivery has already flushed and closed the connection',
	];

	/** @return array<string, array{string}> */
	public static function unservedDirectories(): array {
		return [
			'app metadata' => ['appinfo'],
			'JSON-LD contexts' => ['context'],
			'frontend sources' => ['src'],
		];
	}

	#[DataProvider('unservedDirectories')]
	public function testADirectoryWithNothingToServeDeniesEveryRequest(string $directory): void {
		$file = dirname(__DIR__, 2) . '/' . $directory . '/.htaccess';

		$this->assertFileExists($file);
		$this->assertMatchesRegularExpression('/^\s*(Deny from all|Require all denied)\s*$/mi', (string)file_get_contents($file));
	}

	/**
	 * A controller that exits skips the framework's response, and with it the
	 * headers and middleware every other answer gets; an actor lookup that
	 * failed used to end that way, with a bare 404 and no body.
	 */
	public function testNoControllerEndsARequestWithExitUnlessListed(): void {
		$exiting = [];
		foreach (glob(dirname(__DIR__, 2) . '/lib/Controller/*.php') ?: [] as $file) {
			foreach (token_get_all((string)file_get_contents($file)) as $token) {
				if (is_array($token) && $token[0] === T_EXIT) {
					$exiting[basename($file)] = true;
				}
			}
		}

		$this->assertSame(array_keys(self::HARD_EXITS), array_keys($exiting));
	}
}
