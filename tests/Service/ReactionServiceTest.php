<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Service\ReactionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the app will accept as a reaction, and what a reaction bar comes out
 * looking like.
 *
 * The emoji test is the important one. It decides what a peer may put in a
 * reaction bar on somebody's timeline, and getting it wrong in either
 * direction is bad: too loose and a remote server writes text into the bar,
 * too tight and ordinary emoji from ordinary servers are dropped.
 */
class ReactionServiceTest extends TestCase {
	public static function acceptedProvider(): array {
		return [
			'a plain emoji' => ['👍'],
			'a heart with its variation selector' => ['❤️'],
			'a flag, which is two regional indicators' => ['🇩🇪'],
			'a skin tone modifier' => ['👍🏽'],
			'a zero-width-joiner family' => ['👨‍👩‍👧'],
			'a rainbow flag, joiner and all' => ['🏳️‍🌈'],
			'space around it is trimmed' => [' 🎉 '],
		];
	}

	#[DataProvider('acceptedProvider')]
	public function testAnEmojiIsAccepted(string $emoji): void {
		$this->assertTrue(ReactionService::isUsableEmoji($emoji));
	}

	public static function refusedProvider(): array {
		return [
			'nothing at all' => [''],
			'only space' => ['   '],
			// the whole reason the check is "is this emoji" and not "is this short"
			'a custom emoji shortcode' => [':blobcat:'],
			'a word' => ['nice'],
			'a single letter' => ['a'],
			'a digit' => ['7'],
			'a sentence' => ['this is not an emoji'],
			'an emoji with a word stuck to it' => ['👍 nice'],
			'punctuation' => ['!'],
			'a keycap, which carries a digit' => ['1️⃣'],
			'an html fragment' => ['<b>'],
			'longer than the column' => [str_repeat('👍', 20)],
		];
	}

	#[DataProvider('refusedProvider')]
	public function testSomethingThatIsNotAnEmojiIsRefused(string $emoji): void {
		$this->assertFalse(ReactionService::isUsableEmoji($emoji));
	}

	/**
	 * A run of symbols that is not text is still not an emoji: `+` and `=` are
	 * neither letters nor punctuation to Unicode, and without the second test
	 * `+=` would be a reaction.
	 */
	public function testARunOfSymbolsIsNotAnEmoji(): void {
		$this->assertFalse(ReactionService::isUsableEmoji('+='));
		$this->assertFalse(ReactionService::isUsableEmoji('^'));
	}

}
