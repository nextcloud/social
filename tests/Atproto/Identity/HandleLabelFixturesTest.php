<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\Identity\HandleMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the handle labels the setup and registration pages
 * preview (`tests/js/utils/blueskyHandle.test.js` is the other): each
 * username in the fixture and the label `HandleMapper::label()` gives it,
 * which the page's port has to give too.
 */
class HandleLabelFixturesTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/handle-labels.json';

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function labels(): array {
		$cases = [];
		foreach (json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) as $case) {
			$cases[json_encode($case['username'], JSON_THROW_ON_ERROR)] = [$case['username'], $case['label']];
		}

		return $cases;
	}

	#[DataProvider('labels')]
	public function testTheFixtureIsWhatTheServerIssues(string $username, string $label): void {
		$this->assertSame($label, HandleMapper::label($username));
	}

	public function testTheFixtureCoversEveryRule(): void {
		$labels = array_column(self::labels(), 1, 0);

		$this->assertSame('frank-k', $labels['Frank_K']);
		$this->assertSame('user', $labels['___']);
		$this->assertSame('u-123', $labels['123']);
		$this->assertSame(63, strlen($labels[str_repeat('a', 80)]));
		$this->assertSame(str_repeat('a', 62), $labels[str_repeat('a', 62) . '_b']);
	}
}
