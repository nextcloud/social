<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * One thing a moderator decided about one account, kept after the fact.
 *
 * `Moderation` is what stands *now* — one row an account, replaced by the next
 * decision and deleted when it is lifted. This is the history it used to
 * throw away: the third silence in a month looked exactly like the first, and
 * whoever lifted the last one took the only evidence that it had happened.
 *
 * A **warning** is a strike whose action is `WARNING`: it applied nothing,
 * which is the step the ladder was missing between doing nothing and taking
 * the account out of the timelines.
 *
 * Mastodon's `AccountWarning`, without the appeal — an appeal needs somewhere
 * for the account to write, and a remote account has no login here at all.
 */
class Strike implements JsonSerializable {
	/** Said something and applied nothing: Mastodon's `none`. */
	public const WARNING = 'none';

	/** One post taken down by a moderator: Mastodon's `delete_statuses`. */
	public const TAKEDOWN = 'delete_statuses';

	/**
	 * The end of whatever stood against the account.
	 *
	 * Not one of Mastodon's actions, because Mastodon has nowhere to put it:
	 * there, lifting is an `AccountWarning` that gets an `appeal`. Here it is
	 * the record that answers "who lifted this, and when" — which the log line
	 * it used to be could not, because it named nobody.
	 */
	public const LIFT = 'lift';

	/** Everything a strike can record: a warning, an applied decision, or the end of one. */
	public const ACTIONS = [
		self::WARNING, Moderation::SILENCE, Moderation::SUSPEND, self::TAKEDOWN, self::LIFT,
	];

	/**
	 * The ones that stand against the account, which is what the browser's
	 * strike column counts.
	 *
	 * A lift is in the history and not in the count: an account warned once
	 * and then let off has one thing standing against it, not two.
	 */
	public const COUNTED = [self::WARNING, Moderation::SILENCE, Moderation::SUSPEND, self::TAKEDOWN];

	public function __construct(
		private string $actorId = '',
		private string $action = self::WARNING,
		private string $text = '',
		private string $moderator = '',
		private int $reportId = 0,
		private int $creation = 0,
		private int $id = 0,
	) {
	}

	public static function fromRow(array $row): self {
		return new self(
			(string)($row['actor_id'] ?? ''),
			(string)($row['action'] ?? self::WARNING),
			(string)($row['text'] ?? ''),
			(string)($row['moderator'] ?? ''),
			(int)($row['report_id'] ?? 0),
			isset($row['creation']) ? (int)strtotime((string)$row['creation']) : 0,
			(int)($row['id'] ?? 0),
		);
	}

	public function getId(): int {
		return $this->id;
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function getAction(): string {
		return $this->action;
	}

	/** Whether this one only said something. */
	public function isWarning(): bool {
		return $this->action === self::WARNING;
	}

	public function getText(): string {
		return $this->text;
	}

	public function getModerator(): string {
		return $this->moderator;
	}

	public function getReportId(): int {
		return $this->reportId;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	/**
	 * Mastodon's `AccountWarning`.
	 *
	 * `appeal` is always null: an appeal is something the account writes back,
	 * and there is nowhere here for a remote account to write it.
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => (string)$this->id,
			'action' => $this->action,
			'text' => $this->text,
			'created_at' => gmdate('Y-m-d\TH:i:s', $this->creation) . '.000Z',
			'target_account_id' => $this->actorId,
			'report_id' => $this->reportId > 0 ? (string)$this->reportId : null,
			'appeal' => null,
		];
	}
}
