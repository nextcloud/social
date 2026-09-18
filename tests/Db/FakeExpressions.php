<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

/**
 * Predicates as readable strings.
 *
 * It implements no interface on purpose: `IExpressionBuilder` carries
 * Doctrine's parameter-type constants in its signature and cannot be loaded —
 * let alone doubled — in a suite that runs without a database. The query
 * builder's own `expr()` has no declared return type, so a stand-in is what a
 * test can put there.
 */
class FakeExpressions {
	public function eq($x, $y, $type = null): string {
		return $x . ' = ' . $y;
	}

	public function neq($x, $y, $type = null): string {
		return $x . ' <> ' . $y;
	}

	public function in($x, $y, $type = null): string {
		return $x . ' IN (' . $y . ')';
	}

	public function andX(...$parts): string {
		return '(' . implode(' AND ', $parts) . ')';
	}

	public function orX(...$parts): string {
		return '(' . implode(' OR ', $parts) . ')';
	}
}
