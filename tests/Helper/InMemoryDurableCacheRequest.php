<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use OCA\Social\Db\DurableCacheRequest;

/**
 * `social_durable_cache` as an array, with the statements' semantics.
 *
 * The real class needs a database the unit suite does not have; this keeps
 * what each statement means — a read that ignores an expired row, an insert
 * that skips a key already present, expired or not — so that `DurableCache`
 * can be tested on its table path. The SQL itself is covered by
 * `tests/Integration/Db/DurableCacheTableTest`.
 */
final class InMemoryDurableCacheRequest extends DurableCacheRequest {
	/** @var array<string, array{value: string, expires: int}> */
	public array $rows = [];

	/** @noinspection PhpMissingParentConstructorInspection */
	public function __construct() {
	}

	#[\Override]
	public function read(string $key, int $now): ?string {
		$row = $this->rows[$key] ?? null;

		return ($row === null || $row['expires'] <= $now) ? null : $row['value'];
	}

	#[\Override]
	public function write(string $key, string $value, int $expires): void {
		$this->rows[$key] = ['value' => $value, 'expires' => $expires];
	}

	#[\Override]
	public function insertIfAbsent(string $key, string $value, int $expires): bool {
		if (array_key_exists($key, $this->rows)) {
			return false;
		}

		$this->rows[$key] = ['value' => $value, 'expires' => $expires];

		return true;
	}

	#[\Override]
	public function replaceValue(string $key, string $value, int $now): bool {
		if ($this->read($key, $now) === null) {
			return false;
		}

		$this->rows[$key]['value'] = $value;

		return true;
	}

	#[\Override]
	public function delete(string $key): void {
		unset($this->rows[$key]);
	}

	#[\Override]
	public function purge(int $now): int {
		$before = count($this->rows);
		$this->rows = array_filter($this->rows, static fn (array $row): bool => $row['expires'] > $now);

		return $before - count($this->rows);
	}
}
