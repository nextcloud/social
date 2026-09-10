<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Command;

use OCA\Social\Command\NoteCreate;

/**
 * `--type` used to be the option that quietly did the opposite of its own help.
 *
 * The help says "public (default)", but an omitted or misspelled value reached
 * Post::setType() as a string it did not recognise, and an unrecognised
 * visibility becomes a direct post — addressed, in that case, to nobody at all.
 * The command exited 0 and printed the object, so nothing suggested the note
 * had gone nowhere.
 */
class NoteCreateTest extends CommandTestCase {
	private const USER = 'admin';

	public function testAMisspelledTypeIsRefusedRatherThanPostedToNobody(): void {
		$tester = $this->tester(NoteCreate::class);

		$code = $this->runNonInteractive($tester, [
			'user_id' => self::USER,
			'content' => 'this note should never be created',
			'--type' => 'folowers',
		]);

		$this->assertSame(1, $code);
		$display = $tester->getDisplay();
		$this->assertStringContainsString('unknown type', $display);
		$this->assertStringContainsString('followers', $display, 'the message names what it would have accepted');
		$this->assertStringNotContainsString('token:', $display, 'nothing was posted');
	}

	public function testAnOmittedTypeIsThePublicItsHelpPromises(): void {
		$tester = $this->tester(NoteCreate::class);

		$code = $this->runNonInteractive($tester, [
			'user_id' => self::USER,
			'content' => 'note without an explicit type ' . time(),
		]);

		$this->assertSame(0, $code);
		$this->assertStringContainsString(
			'https://www.w3.org/ns/activitystreams#Public',
			$tester->getDisplay(),
			'the note is addressed to the public collection, not held as a direct post'
		);
	}
}
