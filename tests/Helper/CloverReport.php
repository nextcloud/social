<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Helper;

use InvalidArgumentException;

/**
 * Line coverage read from Clover reports of the same source tree.
 *
 * Several reports merge line by line: a statement is covered when any report
 * covered it. PHPUnit cannot merge two runs by itself, and the unit and the
 * integration suite are two runs with two configurations, so this is where the
 * two halves meet. Lines count as PHPUnit counts them: `stmt` lines only.
 */
final class CloverReport {
	/** @var array<string, array<int, bool>> path below the root => line => covered */
	private array $lines = [];

	public function __construct(
		private readonly string $root,
	) {
	}

	public function addXml(string $xml): void {
		$previous = libxml_use_internal_errors(true);
		$document = simplexml_load_string($xml);
		libxml_use_internal_errors($previous);
		if ($document === false) {
			throw new InvalidArgumentException('not a Clover report: the XML does not parse');
		}

		$files = $document->xpath('//file');
		if ($files === null || $files === false || $files === []) {
			throw new InvalidArgumentException('not a Clover report: it names no file');
		}

		foreach ($files as $file) {
			$path = $this->relative((string)$file['name']);
			$this->lines[$path] ??= [];
			foreach ($file->line as $line) {
				if ((string)$line['type'] !== 'stmt') {
					continue;
				}
				$number = (int)$line['num'];
				$this->lines[$path][$number] = ($this->lines[$path][$number] ?? false) || (int)$line['count'] > 0;
			}
		}
	}

	/** @return array{covered: int, statements: int} */
	public function total(): array {
		$total = ['covered' => 0, 'statements' => 0];
		foreach ($this->lines as $lines) {
			$total['covered'] += count(array_filter($lines));
			$total['statements'] += count($lines);
		}

		return $total;
	}

	/**
	 * Totals per directory, two levels deep (`lib/Service`, `lib/Db`); a file
	 * directly in `lib/` counts under `lib`.
	 *
	 * @return array<string, array{covered: int, statements: int}> sorted by directory
	 */
	public function byDirectory(): array {
		$directories = [];
		foreach ($this->lines as $path => $lines) {
			$segments = explode('/', $path);
			$directory = count($segments) > 2 ? $segments[0] . '/' . $segments[1] : $segments[0];
			$directories[$directory] ??= ['covered' => 0, 'statements' => 0];
			$directories[$directory]['covered'] += count(array_filter($lines));
			$directories[$directory]['statements'] += count($lines);
		}
		ksort($directories);

		return $directories;
	}

	/**
	 * Percentage rounded down to one decimal, so a report never passes a
	 * floor it is a fraction under; a tree without statements is fully covered.
	 *
	 * @param array{covered: int, statements: int} $total
	 */
	public static function percent(array $total): float {
		if ($total['statements'] === 0) {
			return 100.0;
		}

		return floor($total['covered'] / $total['statements'] * 1000) / 10;
	}

	/**
	 * A Markdown table of line coverage per directory: one column per report
	 * and, for more than one, a column for their merge.
	 *
	 * @param array<string, CloverReport> $reports by label
	 */
	public static function table(array $reports, CloverReport $merged): string {
		$withMerged = count($reports) > 1;
		$columns = $withMerged ? [...array_keys($reports), 'merged'] : array_keys($reports);
		$table = '| Directory | ' . implode(' | ', $columns) . " | Statements |\n";
		$table .= '|---|' . str_repeat('---:|', count($columns)) . "---:|\n";

		$perReport = array_map(static fn (CloverReport $report): array => $report->byDirectory(), $reports);
		foreach ($merged->byDirectory() as $directory => $counts) {
			$cells = [];
			foreach ($perReport as $directories) {
				$cells[] = self::percent($directories[$directory] ?? ['covered' => 0, 'statements' => $counts['statements']]) . ' %';
			}
			if ($withMerged) {
				$cells[] = self::percent($counts) . ' %';
			}
			$table .= "| $directory | " . implode(' | ', $cells) . ' | ' . $counts['statements'] . " |\n";
		}

		$cells = array_map(static fn (CloverReport $report): string => self::percent($report->total()) . ' %', array_values($reports));
		if ($withMerged) {
			$cells[] = self::percent($merged->total()) . ' %';
		}

		return $table . '| **total** | ' . implode(' | ', $cells) . ' | ' . $merged->total()['statements'] . " |\n";
	}

	private function relative(string $path): string {
		$root = rtrim($this->root, '/') . '/';
		if (str_starts_with($path, $root)) {
			return substr($path, strlen($root));
		}

		return ltrim($path, '/');
	}
}
