<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Fuzz;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded mutations of bytes and of decoded documents.
 *
 * Every choice comes from one Mersenne Twister seeded by the caller, so a
 * seed and an iteration count name exactly one sequence of inputs, on every
 * machine: a failure found at night is replayed by running with its seed.
 *
 * Bytes are flipped, overwritten with boundary values, cut short, cut out,
 * repeated, spliced from another seed and grown large. A document — the
 * arrays and scalars `json_decode()` gives — has one of its nodes swapped for
 * a value of another type, nested deep, made huge, given Unicode that is
 * malformed or hostile, dropped, duplicated or replaced by a key another seed
 * uses, which is how a mutation reaches the branch that reads that key.
 */
final class Mutator {
	/** strings that are invalid, unusual or hostile as UTF-8 */
	private const UNICODE = [
		"\xC0\xAF", "\xED\xA0\x80", "\xF4\x90\x80\x80", "\xFF\xFE", "\x00", "\u{202E}", "\u{FEFF}",
		"\u{1F469}\u{200D}\u{1F469}\u{200D}\u{1F467}", "e\u{0301}\u{0301}\u{0301}", "\u{10FFFF}", "\r\n", "\x1b[2J",
	];

	private const BOUNDARY_BYTES = ["\x00", "\x7f", "\x80", "\xff", "\x17", "\x18", "\x19", "\x1a", "\x1b", "\x1f", "\x9f", "\xbf"];

	/** @var list<int|float|string> numbers at and past the edges of what PHP and JSON hold */
	private const NUMBERS = [
		PHP_INT_MAX, PHP_INT_MIN, -1, 0, 1, 9007199254740993, 1.5, -0.0, 1e308, -1e308, 4.9e-324,
		'9999999999999999999999999', '-0', '1e400', '0x1f',
	];

	private Randomizer $random;

	/** @var list<string> the keys the seeds use, for a mutation to set */
	private array $keys = [];

	/**
	 * @param list<mixed> $seeds documents whose keys a mutation may set
	 * @param int $hugeBytes the most a mutation grows one string or input by
	 */
	public function __construct(
		int $seed,
		array $seeds = [],
		private readonly int $hugeBytes = 262144,
	) {
		$this->random = new Randomizer(new Mt19937($seed));
		foreach ($seeds as $document) {
			$this->collectKeys($document);
		}
		$this->keys = array_values(array_unique($this->keys));
	}

	public function int(int $min, int $max): int {
		return $this->random->getInt($min, $max);
	}

	/**
	 * @template T
	 * @param non-empty-list<T> $list
	 * @return T
	 */
	public function pick(array $list): mixed {
		return $list[$this->random->getInt(0, count($list) - 1)];
	}

	/**
	 * One to four mutations of a byte string.
	 *
	 * @param list<string> $others other seeds, to splice from
	 */
	public function bytes(string $bytes, array $others = []): string {
		$rounds = $this->int(1, 4);
		for ($i = 0; $i < $rounds; $i++) {
			$bytes = $this->bytesOnce($bytes, $others);
		}

		return $bytes;
	}

	/** One to three mutations of a decoded document. */
	public function document(mixed $document): mixed {
		$rounds = $this->int(1, 3);
		for ($i = 0; $i < $rounds; $i++) {
			$document = $this->at($document, 0);
		}

		return $document;
	}

	/** @param list<string> $others */
	private function bytesOnce(string $bytes, array $others): string {
		$length = strlen($bytes);
		$at = ($length === 0) ? 0 : $this->int(0, $length - 1);
		$span = ($length === 0) ? 0 : $this->int(1, max(1, min($length - $at, 64)));

		switch ($this->int(0, 9)) {
			case 0:
				if ($length > 0) {
					$bytes[$at] = chr(ord($bytes[$at]) ^ (1 << $this->int(0, 7)));
				}

				return $bytes;
			case 1:
				return ($length === 0) ? $this->pick(self::BOUNDARY_BYTES) : substr_replace($bytes, $this->pick(self::BOUNDARY_BYTES), $at, 1);
			case 2:
				return substr($bytes, 0, $this->int(0, $length));
			case 3:
				return substr_replace($bytes, '', $at, $span);
			case 4:
				return substr_replace($bytes, substr($bytes, $at, $span), $at, 0);
			case 5:
				return substr_replace($bytes, $this->random->getBytes($this->int(1, 16)), $at, 0);
			case 6:
				return substr_replace($bytes, str_repeat("\xff", 8), $at, $this->int(0, min(8, $length - $at)));
			case 7:
				if ($others === []) {
					return strrev($bytes);
				}
				$other = $this->pick($others);
				$from = ($other === '') ? 0 : $this->int(0, strlen($other) - 1);

				return substr($bytes, 0, $at) . substr($other, $from);
			case 8:
				$chunk = ($length === 0) ? 'A' : substr($bytes, $at, $span);

				return substr_replace($bytes, str_repeat($chunk, max(1, intdiv($this->int(1, $this->hugeBytes), strlen($chunk)))), $at, 0);
			default:
				return substr_replace($bytes, $this->pick(self::UNICODE), $at, 0);
		}
	}

	/** A mutation of a random node of the document, found by walking down from the top. */
	private function at(mixed $node, int $depth): mixed {
		if (is_array($node) && $node !== [] && $depth < 32 && $this->int(0, 2) > 0) {
			$keys = array_keys($node);
			$key = $this->pick($keys);
			$node[$key] = $this->at($node[$key], $depth + 1);

			return $node;
		}

		return $this->mutateNode($node);
	}

	private function mutateNode(mixed $node): mixed {
		switch ($this->int(0, 9)) {
			case 0:
				return $this->otherType($node);
			case 1:
				$depth = $this->int(16, 600);
				for ($i = 0; $i < $depth; $i++) {
					$node = $this->int(0, 1) === 0 ? [$node] : ['a' => $node];
				}

				return $node;
			case 2:
				return str_repeat($this->pick(['A', "\u{00e9}", '"', '\\', '<', '%']), $this->int(1024, $this->hugeBytes));
			case 3:
				return $this->pick(self::NUMBERS);
			case 4:
				return is_string($node)
					? substr_replace($node, $this->pick(self::UNICODE), $this->int(0, strlen($node)), 0)
					: $this->pick(self::UNICODE);
			case 5:
				if (is_array($node) && $node !== []) {
					unset($node[$this->pick(array_keys($node))]);
				}

				return $node;
			case 6:
				if (is_array($node) && $node !== []) {
					$node[] = $node[$this->pick(array_keys($node))];
				}

				return $node;
			case 7:
				if ($this->keys !== []) {
					$node = is_array($node) ? $node : [];
					$node[$this->pick($this->keys)] = $this->otherType(null);
				}

				return $node;
			case 8:
				return is_string($node) ? $this->bytesOnce($node, []) : $node;
			default:
				if (is_array($node) && $node !== []) {
					$keys = array_keys($node);
					$value = $node[$this->pick($keys)];
					unset($node[$this->pick($keys)]);
					$node[$this->pick(['', '0', '@context', '__proto__', "\u{202E}", '$type', 'id'])] = $value;
				}

				return $node;
		}
	}

	private function otherType(mixed $node): mixed {
		$choices = [
			fn () => null,
			fn () => $this->int(0, 1) === 1,
			fn () => $this->pick(self::NUMBERS),
			fn () => '',
			fn () => 'https://fuzz.example/' . bin2hex($this->random->getBytes(4)),
			fn () => [],
			fn () => [$node],
			fn () => ['type' => $this->pick(['Note', 'Create', 'Person', 'Announce', 'Undo', 'Delete', 'Tombstone', 'Question', 'Video'])],
			fn () => (object)[],
		];

		return ($this->pick($choices))();
	}

	private function collectKeys(mixed $document): void {
		if (!is_array($document)) {
			return;
		}
		foreach ($document as $key => $value) {
			if (is_string($key)) {
				$this->keys[] = $key;
			}
			$this->collectKeys($value);
		}
	}
}
