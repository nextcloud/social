<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use JsonSerializable;

/**
 * What this instance has decided about one account.
 */
class Moderation implements JsonSerializable {
	/** Reachable, but kept out of the public and global timelines. */
	public const SILENCE = 'silence';

	/** Removed from this instance: content dropped, nothing new accepted. */
	public const SUSPEND = 'suspend';

	public const LEVELS = [self::SILENCE, self::SUSPEND];

	public function __construct(
		private string $actorId = '',
		private string $level = '',
		private string $comment = '',
		private int $creation = 0,
	) {
	}

	public static function fromRow(array $row): self {
		return new self(
			(string)($row['actor_id'] ?? ''),
			(string)($row['level'] ?? ''),
			(string)($row['comment'] ?? ''),
			isset($row['creation']) ? (int)strtotime((string)$row['creation']) : 0,
		);
	}

	public function getActorId(): string {
		return $this->actorId;
	}

	public function getLevel(): string {
		return $this->level;
	}

	public function getComment(): string {
		return $this->comment;
	}

	public function getCreation(): int {
		return $this->creation;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'actor_id' => $this->actorId,
			'level' => $this->level,
			'comment' => $this->comment,
			'creation' => $this->creation,
		];
	}
}
