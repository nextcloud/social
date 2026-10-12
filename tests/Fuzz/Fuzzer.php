<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Fuzz;

use ErrorException;
use Throwable;

/**
 * Feeds a target mutated inputs and collects what went wrong.
 *
 * Each input runs with PHP's warnings and notices turned into exceptions, so
 * a parser that reads past the end of a string or an undefined key is caught
 * as surely as one that throws. Each is timed and its peak memory measured
 * against a budget: an input that takes seconds or hundreds of megabytes to
 * refuse is a denial of service even when it is refused.
 *
 * A failing input is shrunk before it is reported — bytes cut away, nodes
 * dropped — for as long as it still fails the same way, so what the report
 * shows is close to the smallest input that does.
 */
final class Fuzzer {
	/** deprecations raised from inside vendor/ are the dependency's, not a finding */
	private const VENDOR = '/vendor/';

	private const SHRINK_ATTEMPTS = 400;

	public function __construct(
		private readonly int $seed,
		private readonly float $secondsPerInput = 2.0,
		private readonly int $bytesPerInput = 128 * 1024 * 1024,
		private readonly int $hugeBytes = 262144,
	) {
	}

	/**
	 * @return list<Finding> the first findings, at most one per kind of failure
	 */
	public function run(Target $target, int $iterations): array {
		$mutator = new Mutator($this->seed ^ crc32($target->name), $target->seeds, $this->hugeBytes);
		$encoded = [];
		foreach ($target->seeds as $seed) {
			if ($target->encode !== null) {
				$encoded[] = ($target->encode)($seed);
			}
		}

		$findings = [];
		// the seeds themselves first: a target that refuses its own good
		// input is broken before any mutation
		$inputs = ($target->encode !== null) ? $encoded : $target->seeds;
		foreach ($inputs as $input) {
			$this->record($findings, $target, $input, 'seed');
		}

		$count = max(1, (int)round($iterations * $target->share));
		for ($i = 0; $i < $count; $i++) {
			$input = $this->input($target, $mutator, $encoded);
			if (is_string($input) && strlen($input) > $target->maxBytes) {
				$input = substr($input, 0, $target->maxBytes);
			}
			$this->record($findings, $target, $input, 'input ' . $i);
		}

		return array_values($findings);
	}

	/** @param array<string, Finding> $findings */
	private function record(array &$findings, Target $target, mixed $input, string $label): void {
		$failure = $this->attempt($target, $input);
		if ($failure === null || isset($findings[$failure])) {
			return;
		}

		$findings[$failure] = new Finding($target->name, $label, $failure, $this->shrink($target, $input, $failure), $this->seed);
	}

	/** @param list<string> $encoded */
	private function input(Target $target, Mutator $mutator, array $encoded): mixed {
		$seed = $mutator->pick($target->seeds);
		if ($target->encode === null) {
			return $mutator->document($seed);
		}

		if ($mutator->int(0, 1) === 0) {
			try {
				return ($target->encode)($mutator->document($seed));
			} catch (Throwable $e) {
				// a document with no encoding in this format; its bytes are mutated instead
			}
		}

		return $mutator->bytes($mutator->pick($encoded), $encoded);
	}

	/**
	 * Runs one input, and says how it failed, or null when it did not: the
	 * exception's class and where it was thrown, or the budget it broke.
	 */
	public function attempt(Target $target, mixed $input): ?string {
		// an input that kills PHP outright leaves no finding to report, so a
		// run can be told to keep the one about to run in a file
		$trace = getenv('FUZZ_TRACE');
		if (is_string($trace) && $trace !== '') {
			file_put_contents($trace, serialize($input));
		}
		set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
			if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true) && str_contains($file, self::VENDOR)) {
				return true;
			}

			throw new ErrorException($message, 0, $severity, $file, $line);
		});
		memory_reset_peak_usage();
		$memory = memory_get_usage();
		$started = hrtime(true);
		try {
			($target->run)($input);
			$failure = null;
		} catch (Throwable $e) {
			$failure = $target->allows($e)
				? null
				: get_class($e) . ' at ' . self::where($e) . ': ' . self::excerpt($e->getMessage());
		} finally {
			restore_error_handler();
		}

		$seconds = (hrtime(true) - $started) / 1e9;
		$bytes = memory_get_peak_usage() - $memory;
		if ($failure === null && $seconds > $this->secondsPerInput) {
			$failure = sprintf('took %.1f s, more than %.1f s', $seconds, $this->secondsPerInput);
		}
		if ($failure === null && $bytes > $this->bytesPerInput) {
			$failure = sprintf('used %d MB, more than %d MB', intdiv($bytes, 1048576), intdiv($this->bytesPerInput, 1048576));
		}

		return $failure;
	}

	/** The input made smaller while it fails the same way. */
	private function shrink(Target $target, mixed $input, string $failure): mixed {
		if (str_starts_with($failure, 'took ') || str_starts_with($failure, 'used ')) {
			// a budget is not a stable signature to shrink against
			return $input;
		}

		$attempts = 0;
		$changed = true;
		while ($changed && $attempts < self::SHRINK_ATTEMPTS) {
			$changed = false;
			foreach (self::smaller($input) as $candidate) {
				if (++$attempts > self::SHRINK_ATTEMPTS) {
					break;
				}
				if ($this->attempt($target, $candidate) === $failure) {
					$input = $candidate;
					$changed = true;
					break;
				}
			}
		}

		return $input;
	}

	/**
	 * Inputs a little smaller than this one: halves and smaller chunks cut out
	 * of a string, one entry dropped from or one entry shrunk in an array.
	 *
	 * @return iterable<mixed>
	 */
	private static function smaller(mixed $input): iterable {
		if (is_string($input)) {
			$length = strlen($input);
			for ($chunk = intdiv($length, 2); $chunk >= 1; $chunk = intdiv($chunk, 2)) {
				for ($at = 0; $at + $chunk <= $length; $at += $chunk) {
					yield substr($input, 0, $at) . substr($input, $at + $chunk);
				}
			}

			return;
		}

		if (!is_array($input)) {
			return;
		}

		foreach (array_keys($input) as $key) {
			$without = $input;
			unset($without[$key]);
			yield array_is_list($input) ? array_values($without) : $without;
		}
		foreach ($input as $key => $value) {
			foreach (self::smaller($value) as $smaller) {
				$copy = $input;
				$copy[$key] = $smaller;
				yield $copy;
			}
		}
	}

	/** Where an exception was thrown, relative to the app. */
	private static function where(Throwable $e): string {
		$root = dirname(__DIR__, 2) . '/';

		return str_replace($root, '', $e->getFile()) . ':' . $e->getLine();
	}

	private static function excerpt(string $message): string {
		$message = (string)preg_replace('/[^\x20-\x7e]/', '?', $message);

		return (strlen($message) > 160) ? substr($message, 0, 160) . '…' : $message;
	}
}
