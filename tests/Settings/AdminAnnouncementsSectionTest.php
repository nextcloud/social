<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Settings;

use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The announcements section of the administration page.
 *
 * It carries no data from PHP — the list is read from `/admin/announcements`
 * when the page loads — so what it has to get right is the set of ids
 * `src/adminAnnouncements.js` looks its controls up by. A rename on either
 * side is a section that renders, does nothing, and says nothing about why,
 * which is what these assertions are here to catch: they read the ids out of
 * the script and require every one of them in the markup.
 */
class AdminAnnouncementsSectionTest extends TestCase {
	/** Renders the settings template, with warnings promoted to failures. */
	private function render(): string {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$l->method('n')->willReturnCallback(
			fn (string $singular, string $plural, int $count): string
				=> str_replace('%n', (string)$count, $count === 1 ? $singular : $plural)
		);

		$_ = [
			'reports' => [],
			'accessType' => 'all_but',
			'accessList' => [],
			'retentionDays' => 0,
			'federation' => [
				'waiting' => 0,
				'running' => 0,
				'failing' => 0,
				'atRisk' => 0,
				'maxTries' => 15,
				'truncated' => false,
				'instances' => [],
			],
			'moderation' => [],
		];

		set_error_handler(static function (int $severity, string $message): bool {
			throw new \ErrorException($message, 0, $severity);
		});

		try {
			ob_start();
			require __DIR__ . '/../../templates/settings/admin.php';

			return (string)ob_get_clean();
		} finally {
			// pop ours off the stack rather than installing a replacement, so
			// whatever PHPUnit had is exactly what the next test gets
			restore_error_handler();
		}
	}

	private function script(): string {
		return (string)file_get_contents(__DIR__ . '/../../src/adminAnnouncements.js');
	}

	public function testTheSectionIsOnThePage(): void {
		$this->assertStringContainsString('id="social-announcements"', $this->render());
	}

	public function testEveryControlTheScriptLooksUpIsInTheMarkup(): void {
		$html = $this->render();

		preg_match_all("/getElementById\('(social-[a-z-]+)'\)/", $this->script(), $matches);
		$ids = array_unique($matches[1]);

		$this->assertNotEmpty($ids, 'the script looks nothing up, which cannot be right');
		foreach ($ids as $id) {
			$this->assertStringContainsString('id="' . $id . '"', $html, $id . ' is not in the markup');
		}
	}

	public function testTheRowsAreLeftForTheRouteToFillIn(): void {
		// a list rendered here would disagree with the routes the section
		// writes through the moment an announcement was posted
		$this->assertStringContainsString(
			'<tbody id="social-announcements-list"></tbody>', $this->render()
		);
	}

	public function testTheFormOffersBothBoundsAndTheWholeDayFlag(): void {
		$html = $this->render();

		$this->assertStringContainsString('id="social-announcement-starts"', $html);
		$this->assertStringContainsString('id="social-announcement-ends"', $html);
		$this->assertStringContainsString('id="social-announcement-all-day"', $html);
	}

	public function testTheHintSaysWhatARangeDoesAndWhatRemovingDoes(): void {
		// both are things an admin cannot find out by trying them safely
		$html = $this->render();

		$this->assertStringContainsString('only shown between them', $html);
		$this->assertStringContainsString('takes it away from everybody', $html);
	}
}
