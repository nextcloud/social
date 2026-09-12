<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\StreamCard;

/**
 * Mastodon's Trends::Link entity: a link preview with the counts that put it
 * in the list.
 *
 * A PreviewCard and nothing more, plus `history` — so the card half is the
 * app's own `StreamCard`, serialised by the one class that decides what a
 * preview looks like to a client. A second rendering of the same fields here
 * would let a trending link and the card on the post it came from disagree
 * about the same page.
 *
 * `history` carries a single bucket and `accounts` in it is always `0`, which
 * is the same shape and the same limitation `HashtagService::tagEntity()`
 * reports for a hashtag: this instance counts uses, not distinct accounts.
 */
class TrendingLink implements JsonSerializable {
	public function __construct(
		private StreamCard $card,
		private int $shares,
	) {
	}

	public function getCard(): StreamCard {
		return $this->card;
	}

	public function getShares(): int {
		return $this->shares;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return array_merge(
			$this->card->jsonSerialize(),
			[
				'history' => [
					[
						'day' => (string)strtotime('today midnight'),
						'accounts' => '0',
						'uses' => (string)$this->shares,
					]
				],
			]
		);
	}
}
