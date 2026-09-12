<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCP\DB\Schema\ColumnType;
use OCP\DB\Schema\IColumn;

/**
 * A column a migration asked for, recorded rather than created.
 *
 * Only the setters a migration in this app actually calls do anything; the
 * rest report what they were given. See {@see FakeTable}.
 */
class FakeColumn implements IColumn {
	public function __construct(
		private string $name,
		private string|ColumnType $type,
		private array $options = [],
	) {
	}

	public function setType(string|ColumnType $type): self {
		$this->type = $type;

		return $this;
	}

	public function setLength(?int $length): self {
		$this->options['length'] = $length;

		return $this;
	}

	public function setPrecision(int $precision): self {
		$this->options['precision'] = $precision;

		return $this;
	}

	public function setScale(int $scale): self {
		$this->options['scale'] = $scale;

		return $this;
	}

	public function setUnsigned(bool $unsigned): self {
		$this->options['unsigned'] = $unsigned;

		return $this;
	}

	public function setFixed(bool $fixed): self {
		$this->options['fixed'] = $fixed;

		return $this;
	}

	public function setNotnull(bool $notnull): self {
		$this->options['notnull'] = $notnull;

		return $this;
	}

	public function setDefault(mixed $default): self {
		$this->options['default'] = $default;

		return $this;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getType(): ColumnType {
		return $this->type instanceof ColumnType ? $this->type : ColumnType::from($this->type);
	}

	public function getLength(): ?int {
		return $this->options['length'] ?? null;
	}

	public function getPrecision(): int {
		return $this->options['precision'] ?? 10;
	}

	public function getScale(): int {
		return $this->options['scale'] ?? 0;
	}

	public function getUnsigned(): bool {
		return $this->options['unsigned'] ?? false;
	}

	public function getFixed(): bool {
		return $this->options['fixed'] ?? false;
	}

	public function getNotnull(): bool {
		return $this->options['notnull'] ?? true;
	}

	public function getDefault(): mixed {
		return $this->options['default'] ?? null;
	}

	public function getAutoincrement(): bool {
		return $this->options['autoincrement'] ?? false;
	}

	/** The options as the migration passed them, for assertions. */
	public function options(): array {
		return $this->options;
	}

	/** The type as the migration passed it, without normalising to the enum. */
	public function rawType(): string|ColumnType {
		return $this->type;
	}
}
