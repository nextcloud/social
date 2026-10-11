<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Fuzz;

use Closure;
use Throwable;

/**
 * One parser under fuzz: what it is fed, how, and what it may throw.
 *
 * `seeds` are well-formed inputs — documents, or values of the parser's own
 * model — that mutations start from. With `encode`, a seed is turned into the
 * bytes the parser reads, and a mutation may change the document before it
 * is encoded or the bytes after; without it the mutated document itself is
 * handed to `run`. `allowed` are the exceptions the parser documents for
 * input it refuses: anything else it throws, any PHP warning or notice it
 * raises, and a `run` that finds a property broken is a finding.
 */
final class Target {
	/**
	 * @param list<mixed> $seeds
	 * @param Closure(mixed): void $run
	 * @param list<class-string<Throwable>> $allowed
	 * @param (Closure(mixed): string)|null $encode
	 * @param float $share how many of the run's inputs this target gets, as a share of the iteration count
	 * @param int $maxBytes the longest input the parser can be handed, where its caller bounds what it reads
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $seeds,
		public readonly Closure $run,
		public readonly array $allowed,
		public readonly ?Closure $encode = null,
		public readonly float $share = 1.0,
		public readonly int $maxBytes = PHP_INT_MAX,
	) {
	}

	public function allows(Throwable $e): bool {
		foreach ($this->allowed as $class) {
			if ($e instanceof $class) {
				return true;
			}
		}

		return false;
	}
}
