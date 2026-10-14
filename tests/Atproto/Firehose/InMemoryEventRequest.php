<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Firehose;

use OCA\Social\Atproto\Model\Event;
use OCA\Social\Db\AtprotoEventRequest;

/**
 * The event table in an array: sequence numbers handed out in order, as
 * the database's autoincrement does, and the same queries over them.
 */
class InMemoryEventRequest extends AtprotoEventRequest {
	/** @var array<int, Event> seq => event */
	public array $events = [];
	public int $next = 1;

	public function __construct() {
	}

	#[\Override]
	public function append(string $did, string $kind, string $body, int $time): int {
		$seq = $this->next++;
		$this->events[$seq] = new Event($seq, $did, $kind, $body, $time);

		return $seq;
	}

	#[\Override]
	public function after(int $seq, int $limit = 200): array {
		$after = array_filter($this->events, static fn (Event $event): bool => $event->seq > $seq);
		ksort($after);

		return array_slice(array_values($after), 0, $limit);
	}

	#[\Override]
	public function latestSeq(): int {
		return $this->events === [] ? 0 : max(array_keys($this->events));
	}

	#[\Override]
	public function oldestSeq(): int {
		return $this->events === [] ? 0 : min(array_keys($this->events));
	}

	#[\Override]
	public function countSince(int $time): int {
		return count(array_filter($this->events, static fn (Event $event): bool => $event->time > $time));
	}

	#[\Override]
	public function pruneBefore(int $time): int {
		$before = count($this->events);
		$this->events = array_filter($this->events, static fn (Event $event): bool => $event->time >= $time);

		return $before - count($this->events);
	}
}
