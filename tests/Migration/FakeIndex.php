<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCP\DB\Schema\IIndex;

/**
 * An index as {@see FakeTable::getIndexes()} reports it.
 */
class FakeIndex implements IIndex {
	/**
	 * @param non-empty-string $name
	 * @param list<string> $columns
	 */
	public function __construct(
		private string $name,
		private array $columns,
		private bool $unique,
		private bool $primary = false,
	) {
	}

	public function getName(): string {
		return $this->name;
	}

	public function getColumns(): array {
		return $this->columns;
	}

	public function isUnique(): bool {
		return $this->unique || $this->primary;
	}

	public function isPrimary(): bool {
		return $this->primary;
	}

	public function isSimpleIndex(): bool {
		return !$this->unique && !$this->primary;
	}

	public function hasColumnAtPosition(string $name, int $position = 0): bool {
		return ($this->columns[$position] ?? null) === $name;
	}

	public function spansColumns(array $columnNames): bool {
		$mine = $this->columns;
		sort($mine);
		sort($columnNames);

		return $mine === $columnNames;
	}
}
