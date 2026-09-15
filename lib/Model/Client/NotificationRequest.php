<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\Client;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;

/**
 * Mastodon's NotificationRequest: everything one held-back account has sent,
 * as one row the reader can decide about.
 *
 * The point of it is that the decision is about the account and not about the
 * notification. Somebody the policy holds back has usually sent more than one
 * thing, and being asked about each in turn is what makes a filtered inbox
 * worse than an unfiltered one.
 *
 * Its `id` is the sender's account id. Mastodon's is a row id of its own
 * because it stores these; here they are derived from the notifications that
 * are already stored, so the account is the only stable name there is — and it
 * is the one thing the accept and dismiss routes need anyway.
 */
class NotificationRequest implements \JsonSerializable {
	public function __construct(
		private Person $account,
		private int $count,
		private int $lastAt,
		private int $firstAt,
		private ?Stream $lastStatus = null,
	) {
	}

	public function getAccount(): Person {
		return $this->account;
	}

	public function getCount(): int {
		return $this->count;
	}

	#[\Override]
	public function jsonSerialize(): array {
		$this->account->setExportFormat(ACore::FORMAT_LOCAL);

		$entity = [
			'id' => (string)$this->account->getNid(),
			'created_at' => $this->date($this->firstAt),
			'updated_at' => $this->date($this->lastAt),
			'account' => $this->account,
			'notifications_count' => (string)$this->count,
		];

		if ($this->lastStatus !== null) {
			$this->lastStatus->setExportFormat(ACore::FORMAT_LOCAL);
			$entity['last_status'] = $this->lastStatus;
		}

		return $entity;
	}

	private function date(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z';
	}
}
