<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Listeners\FilesScriptsListener;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\TestCase;

class FilesScriptsListenerTest extends TestCase {
	public function testIsAnEventListener(): void {
		$this->assertInstanceOf(IEventListener::class, new FilesScriptsListener());
	}

	/**
	 * Registering the script needs the server's script registry, so an
	 * unrelated event must return before reaching it -- and the event itself
	 * is the Files app's class, absent here, so that is the path there is.
	 */
	public function testUnrelatedEventsAreIgnored(): void {
		(new FilesScriptsListener())->handle(new Event());

		$this->addToAssertionCount(1);
	}

	/**
	 * The Files event is answered with the one self-contained entry: not the
	 * framework chunk, which every other page of this app loads first and
	 * which a Files page must not pay for. The order is pinned by name in
	 * `tests/js/bundles.test.js`, next to the build that makes it true.
	 */
	public function testAnswersTheFilesEventWithTheSelfContainedEntry(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../lib/Listeners/FilesScriptsListener.php');

		$this->assertStringContainsString("addInitScript('social', 'social-filesAction')", $source);
		$this->assertStringNotContainsString("'social-framework'", $source);
	}
}
