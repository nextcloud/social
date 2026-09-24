<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

use InvalidArgumentException;

/**
 * Numeric stream identifiers are stored as BIGINTs and often exceed PHP_INT_MAX
 * on 32-bit installations. Keep their decimal representation intact at every
 * PHP boundary; SQL performs the numeric comparisons against the BIGINT column.
 */
final class Nid {
	/** Normalize an integer or decimal string without converting it to a PHP int. */
	public static function normalize(int|string $nid): string {
		$nid = (string)$nid;
		if ($nid === '' || !ctype_digit($nid)) {
			throw new InvalidArgumentException('A stream nid must be a non-negative decimal integer');
		}

		$normalized = ltrim($nid, '0');

		return $normalized === '' ? '0' : $normalized;
	}

	/** Keep representable stored identifiers native; retain oversized ones as strings. */
	public static function fromStorage(int|string $nid): int|string {
		if (is_int($nid)) {
			return $nid;
		}

		$normalized = self::normalize($nid);
		if (self::compare($normalized, (string)PHP_INT_MAX) <= 0) {
			return (int)$normalized;
		}

		return $normalized;
	}

	/** Compare decimal identifiers without float or machine-integer coercion. */
	public static function compare(int|string $left, int|string $right): int {
		$left = self::normalize($left);
		$right = self::normalize($right);
		$lengthComparison = strlen($left) <=> strlen($right);

		$comparison = $lengthComparison !== 0 ? $lengthComparison : strcmp($left, $right);

		return $comparison <=> 0;
	}

	/** Add one without converting an identifier to a native integer. */
	public static function increment(int|string $nid): string {
		$digits = str_split(self::normalize($nid));
		for ($index = count($digits) - 1; $index >= 0; $index--) {
			if ($digits[$index] !== '9') {
				$digits[$index] = (string)((int)$digits[$index] + 1);
				return implode('', $digits);
			}
			$digits[$index] = '0';
		}

		return '1' . implode('', $digits);
	}

	/** Subtract one without converting an identifier to a native integer. */
	public static function decrement(int|string $nid): string {
		$digits = str_split(self::normalize($nid));
		for ($index = count($digits) - 1; $index >= 0; $index--) {
			if ($digits[$index] !== '0') {
				$digits[$index] = (string)((int)$digits[$index] - 1);
				return self::normalize(implode('', $digits));
			}
			$digits[$index] = '9';
		}

		return '0';
	}

	/**
	 * Compose `publishedTime * limit + random` without overflowing PHP_INT_MAX.
	 * `$random` must fit in the fixed-width suffix used by the nid generator.
	 */
	public static function fromPublishedTime(int $publishedTime, int $random, int $limit): string {
		if ($publishedTime < 0 || $random < 0 || $limit < 2 || $random >= $limit) {
			throw new InvalidArgumentException('Invalid component for a stream nid');
		}

		$suffixWidth = strlen((string)$limit) - 1;

		return $publishedTime . str_pad((string)$random, $suffixWidth, '0', STR_PAD_LEFT);
	}
}
