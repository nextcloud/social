<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use JsonSerializable;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;

/**
 * Mastodon's Suggestion entity: an account to follow, and where the
 * suggestion came from.
 *
 * `sources` is the current field and `source` its deprecated predecessor. Both
 * are sent, because clients in the wild read one or the other and a client
 * that reads the old one would otherwise render every suggestion unlabelled.
 *
 * Only two of Mastodon's source values are ever used here, and each is a
 * description of a real query rather than a category this app aspires to:
 * `friends_of_friends` for an account followed by accounts the viewer follows,
 * and `most_interactions` for one that is simply posting here. There is no
 * `featured` source because nothing on this instance promotes accounts by
 * hand.
 */
class Suggestion implements JsonSerializable {
	/** Followed by somebody the viewer follows. */
	public const SOURCE_FRIENDS = 'friends_of_friends';
	/** Active on this instance and open to being found. */
	public const SOURCE_ACTIVE = 'most_interactions';

	/** @var array<string, string> the deprecated `source` each `sources` maps to */
	private const LEGACY_SOURCE = [
		self::SOURCE_FRIENDS => 'past_interactions',
		self::SOURCE_ACTIVE => 'global',
	];

	public function __construct(
		private Person $account,
		private string $source = self::SOURCE_ACTIVE,
	) {
	}

	public function getAccount(): Person {
		return $this->account;
	}

	public function getSource(): string {
		return $this->source;
	}

	#[\Override]
	public function jsonSerialize(): array {
		$this->account->setExportFormat(ACore::FORMAT_LOCAL);

		return [
			'source' => self::LEGACY_SOURCE[$this->source] ?? 'global',
			'sources' => [$this->source],
			'account' => $this->account,
		];
	}
}
