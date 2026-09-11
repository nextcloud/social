<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\Status;
use PHPUnit\Framework\TestCase;

/**
 * `quote_id` on `POST /api/v1/statuses`: what a Mastodon 4.5 client sends to
 * quote a post.
 */
class StatusQuoteTest extends TestCase {
	public function testTheQuotedStatusIdIsRead(): void {
		$status = (new Status())->import(['status' => 'look', 'quote_id' => '11']);

		$this->assertSame('11', $status->getQuotedId());
	}

	/**
	 * Kept as a string rather than an int: clients send the numeric status id,
	 * but the web UI and anything speaking ActivityPub name a post by its URI,
	 * and `(int)'https://…'` is 0 — a quote of the post with id zero.
	 */
	public function testAQuotedStatusMayBeNamedByItsUri(): void {
		$status = (new Status())->import([
			'status' => 'look',
			'quote_id' => 'https://mastodon.social/users/bob/statuses/111',
		]);

		$this->assertSame('https://mastodon.social/users/bob/statuses/111', $status->getQuotedId());
	}

	public function testAStatusThatQuotesNothingHasNoQuotedId(): void {
		$this->assertSame('', (new Status())->import(['status' => 'hello'])->getQuotedId());
	}

	/** An id sent as a JSON number is still an id. */
	public function testANumericQuoteIdIsAccepted(): void {
		$this->assertSame('11', (new Status())->import(['status' => 'look', 'quote_id' => 11])->getQuotedId());
	}
}
