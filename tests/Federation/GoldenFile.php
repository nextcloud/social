<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use PHPUnit\Framework\Assert;

/**
 * Golden files for documents this app puts on the wire.
 *
 * A golden is the complete JSON of one document, stored under
 * `tests/Federation/golden/<name>.json` and compared whole, so a renamed key,
 * a changed `@context` or a value that changed type fails a test instead of
 * shipping. Values that differ from run to run are replaced before the
 * comparison (see `normalise()`), each by a numbered placeholder, so two
 * places that carried the same random id still carry the same placeholder.
 *
 * `UPDATE_GOLDEN=1` rewrites the files instead of comparing against them:
 *
 *     UPDATE_GOLDEN=1 php vendor/bin/phpunit -c tests/phpunit.xml tests/Federation
 *
 * and `git diff tests/Federation/golden` is then the change to the wire.
 */
final class GoldenFile {
	public const DIR = __DIR__ . '/golden';

	/** Two days either side of now: what the code under test stamped itself. */
	private const NOW_WINDOW = 172800;

	/** @var array<string, string> */
	private array $placeholders = [];

	/** @var array<string, int> */
	private array $counters = [];

	public function __construct(
		private readonly int $now,
	) {
	}

	/**
	 * The document as JSON with every volatile value replaced:
	 *
	 * - a PEM block (public keys) becomes `<pem>`;
	 * - `signatureValue` becomes `<signatureValue>`;
	 * - a UUID, a path segment of 8, 16 or 32 hex digits, or any other run of
	 *   8 to 32 hex digits holding a letter and a digit, becomes `<hex:N>`;
	 * - a date within two days of now, in any format `strtotime()` reads,
	 *   becomes `<now>`;
	 * - a run of 10 or more digits whose first ten are a Unix time within two
	 *   days of now (`ConfigService::generateId()`, millisecond stamps)
	 *   becomes `<stamp:N>`;
	 * - the base64url stamp of a quote authorization is decoded and shown as
	 *   `<base64url:…>` around the normalised id it encodes.
	 *
	 * Fixed dates and ids the test chose itself are left as they are.
	 */
	public static function normalise(array $document): array {
		$normaliser = new self(time());

		return $normaliser->walk($document);
	}

	public static function encode(array $document): string {
		return json_encode(
			$document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		) . "\n";
	}

	/**
	 * What a JsonSerializable becomes on the wire: `json_encode()` with the
	 * flags the delivery queue and the controllers use, read back as arrays.
	 */
	public static function wire(mixed $document): array {
		$decoded = json_decode((string)json_encode($document, JSON_UNESCAPED_SLASHES), true);
		Assert::assertIsArray($decoded, 'the document encodes to a JSON object');

		return $decoded;
	}

	public static function path(string $name): string {
		return self::DIR . '/' . $name . '.json';
	}

	public static function isUpdating(): bool {
		return getenv('UPDATE_GOLDEN') === '1';
	}

	/**
	 * Compares the normalised document with its golden file, or writes the
	 * file when UPDATE_GOLDEN=1.
	 */
	public static function assertMatches(string $name, array $document): void {
		$actual = self::encode(self::normalise($document));
		$path = self::path($name);

		if (self::isUpdating()) {
			if (!is_dir(self::DIR)) {
				mkdir(self::DIR, 0777, true);
			}
			file_put_contents($path, $actual);
		}

		Assert::assertFileExists($path, 'no golden file for ' . $name . ': run the test with UPDATE_GOLDEN=1');
		Assert::assertSame(
			(string)file_get_contents($path),
			$actual,
			'the wire format of ' . $name . ' changed; if that is intended, regenerate with UPDATE_GOLDEN=1'
			. ' and review the diff of ' . basename($path)
		);
	}

	private function walk(array $data): array {
		$result = [];
		foreach ($data as $key => $value) {
			if ($key === 'signatureValue' && is_string($value)) {
				$result[$key] = '<signatureValue>';
				continue;
			}

			$result[$key] = match (true) {
				is_array($value) => $this->walk($value),
				is_string($value) => $this->string($value),
				default => $value,
			};
		}

		return $result;
	}

	private function string(string $value): string {
		if (str_contains($value, '-----BEGIN')) {
			return '<pem>';
		}

		if ($this->isRecentDate($value)) {
			return '<now>';
		}

		// a quote authorization is named by the quoting post's id, base64url
		// encoded: decoded, normalised like any other id, and shown as such
		$value = (string)preg_replace_callback(
			'#/quote_authorizations/([A-Za-z0-9_-]+)#',
			fn (array $m): string => '/quote_authorizations/<base64url:'
				. $this->string((string)base64_decode(strtr($m[1], '-_', '+/'), true)) . '>',
			$value
		);

		$value = (string)preg_replace_callback(
			'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
			fn (array $m): string => $this->placeholder('hex', strtolower($m[0])),
			$value
		);

		// a short random id as a path segment of its own (`#like/<8 hex>`),
		// which may happen to be all digits or all letters
		$value = (string)preg_replace_callback(
			'#(?<=/)(?:[0-9a-f]{8}|[0-9a-f]{16}|[0-9a-f]{32})(?![0-9A-Za-z])#',
			fn (array $m): string => $this->placeholder('hex', $m[0]),
			$value
		);

		$value = (string)preg_replace_callback(
			'/(?<![0-9A-Za-z])[0-9a-f]{8,32}(?![0-9A-Za-z])/',
			fn (array $m): string => (preg_match('/[a-f]/', $m[0]) === 1 && preg_match('/[0-9]/', $m[0]) === 1)
				? $this->placeholder('hex', $m[0])
				: $m[0],
			$value
		);

		return (string)preg_replace_callback(
			'/(?<![0-9])[0-9]{10,}(?![0-9])/',
			fn (array $m): string => (abs((int)substr($m[0], 0, 10) - $this->now) < self::NOW_WINDOW)
				? $this->placeholder('stamp', $m[0])
				: $m[0],
			$value
		);
	}

	private function isRecentDate(string $value): bool {
		if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) !== 1) {
			return false;
		}

		$time = strtotime($value);

		return $time !== false && abs($time - $this->now) < self::NOW_WINDOW;
	}

	private function placeholder(string $kind, string $value): string {
		$key = $kind . "\0" . $value;
		if (!isset($this->placeholders[$key])) {
			$this->counters[$kind] = ($this->counters[$kind] ?? 0) + 1;
			$this->placeholders[$key] = '<' . $kind . ':' . $this->counters[$kind] . '>';
		}

		return $this->placeholders[$key];
	}
}
