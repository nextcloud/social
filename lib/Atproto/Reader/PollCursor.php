<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Reader;

use UnexpectedValueException;

/** A descending feed's committed timestamp and an unfinished walk back to it. */
final class PollCursor {
	public readonly string $since;
	public readonly string $newest;
	public readonly string $page;

	public function __construct(string $stored) {
		if (!str_starts_with($stored, '{')) {
			$this->since = $stored;
			$this->newest = $stored;
			$this->page = '';

			return;
		}

		$data = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data) || !is_string($data['since'] ?? null) || $data['since'] === ''
			|| !is_string($data['newest'] ?? null) || !is_string($data['page'] ?? null) || $data['page'] === '') {
			throw new UnexpectedValueException('Invalid Bluesky polling checkpoint');
		}
		$this->since = $data['since'];
		$this->newest = $data['newest'];
		$this->page = $data['page'];
	}

	/** @return array<string, string> the next page's query parameter */
	public function parameters(): array {
		return $this->page === '' ? [] : ['cursor' => $this->page];
	}

	/**
	 * Commit the newest timestamp only after reaching the previous one or the
	 * end. An account's first read remains a snapshot of its newest page.
	 */
	public function after(string $newest, mixed $next, bool $reached): string {
		if (!is_string($next)) {
			throw new UnexpectedValueException('Invalid Bluesky pagination cursor');
		}
		$newest = max($this->newest, $newest);
		if ($this->since === '' || $reached || $next === '') {
			return $newest;
		}
		if ($next === $this->page) {
			throw new UnexpectedValueException('Bluesky pagination cursor did not advance');
		}

		return json_encode(['since' => $this->since, 'newest' => $newest, 'page' => $next], JSON_THROW_ON_ERROR);
	}
}
