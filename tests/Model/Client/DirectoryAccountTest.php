<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\DirectoryAccount;
use PHPUnit\Framework\TestCase;

/**
 * Everything on one of these was written by a stranger's server, which is what
 * every assertion below is about.
 */
class DirectoryAccountTest extends TestCase {
	private function account(): DirectoryAccount {
		return new DirectoryAccount('jens@chaos.social', 'chaos.social', 'mastodon');
	}

	public function testTheHandleIsSplitRatherThanStoredTwice(): void {
		$account = $this->account();

		$this->assertSame('jens', $account->getUsername());
		$this->assertSame('chaos.social', $account->getHost());
	}

	/**
	 * A bio arrives as HTML on Mastodon and as plain text on Misskey. The one
	 * thing the two must not be is "sometimes markup, rendered".
	 */
	public function testABioIsDeliveredAsText(): void {
		$account = $this->account();
		$account->setNote('<p>Photographer.</p><p>Lives in <a href="https://x.example">Berlin</a>.</p>');

		$this->assertSame('Photographer. Lives in Berlin.', $account->getNote());
	}

	public function testEntitiesInABioAreDecodedRatherThanShown(): void {
		$account = $this->account();
		$account->setNote('Tom &amp; Jerry');

		$this->assertSame('Tom & Jerry', $account->getNote());
	}

	/** A script tag in a bio is text like everything else here, not markup. */
	public function testMarkupInABioCannotSurviveAsMarkup(): void {
		$account = $this->account();
		$account->setNote('<script>alert(1)</script>hello');

		$this->assertStringNotContainsString('<', $account->getNote());
	}

	public function testABioIsCappedSoOneRowCannotTakeTheWholePage(): void {
		$account = $this->account();
		$account->setNote(str_repeat('a', 900));

		$this->assertSame(DirectoryAccount::MAX_NOTE, mb_strlen($account->getNote()));
	}

	/** Only a plain http(s) URL ever reaches an `<img src>`. */
	public function testAnAvatarThatIsNotAWebAddressIsDropped(): void {
		$account = $this->account();
		$account->setAvatar('javascript:alert(1)');

		$this->assertSame('', $account->getAvatar());
	}

	public function testAnAvatarOnTheWebIsKept(): void {
		$account = $this->account();
		$account->setAvatar('https://chaos.social/avatars/jens.png');

		$this->assertSame('https://chaos.social/avatars/jens.png', $account->getAvatar());
	}

	/** `-1` is "the directory did not say", which is not the same as none. */
	public function testAnUnstatedCountIsNotZero(): void {
		$this->assertSame(-1, $this->account()->getFollowersCount());
	}

	/**
	 * The handle is the durable reference and the one thing a client may act
	 * on; the remote `id` is a row number in somebody else's database and is
	 * deliberately not carried.
	 */
	public function testTheWireShapeCarriesTheHandleAndNoForeignId(): void {
		$wire = $this->account()->jsonSerialize();

		$this->assertSame('jens@chaos.social', $wire['acct']);
		$this->assertArrayNotHasKey('id', $wire);
	}
}
