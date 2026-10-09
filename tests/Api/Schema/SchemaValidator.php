<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Api\Schema;

use InvalidArgumentException;
use stdClass;

/**
 * A small validator for the subset of JSON Schema the Mastodon entity schemas
 * next to it use. It checks a payload *as a client decodes it*: objects and
 * lists are told apart (`{}` is not `[]`), an integer is not a float or a
 * numeric string, and a key that is present with `null` is not a key that is
 * missing.
 *
 * Keywords: `type` (one name or a list), `enum`, `const`, `format`,
 * `properties`, `required`, `additionalProperties` (a schema or `false`),
 * `items`, `minItems`, `minimum`, `minLength`, `pattern`, `allOf`, `anyOf`,
 * OpenAPI's `nullable` (null is accepted whatever else the schema says, which
 * is what makes `{"$ref": "Status", "nullable": true}` read naturally), and
 * `$ref` — either another entity by file name (`"Account"`) or a local
 * definition (`"#/$defs/Field"`, or `"Status#/$defs/Mention"` in another
 * file). `description` and `$comment` are read by people only.
 *
 * Formats:
 * - `datetime`: Mastodon's `2026-10-09T12:34:56.000Z` — milliseconds and `Z`
 * - `date`: a calendar date, `2026-10-09`
 * - `iso8601`: any ISO 8601 date-time with a zone, for the one field Mastodon
 *   writes with an offset rather than `Z`
 * - `id`: a string of digits, which is what every Mastodon id is on the wire
 * - `url`: an absolute http(s) URL
 * - `unix-string`: a Unix timestamp written as a string of digits
 * - `language`: an ISO 639 code, optionally with a region or script subtag
 */
final class SchemaValidator {
	/** @var array<string, array> */
	private array $schemas = [];

	public function __construct(
		private string $directory = __DIR__ . '/mastodon',
	) {
	}

	/**
	 * The payload the way a client receives it: through `json_encode()`, as
	 * the JSON response does, and back with objects kept as objects.
	 */
	public static function wire(mixed $payload): mixed {
		return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
	}

	/** @return string[] the entity names there are schemas for */
	public function entities(): array {
		$names = array_map(
			static fn (string $file): string => basename($file, '.json'),
			glob($this->directory . '/*.json') ?: []
		);
		sort($names);

		return $names;
	}

	/**
	 * Every way the (already decoded) payload departs from the entity's
	 * schema, one line each with the JSON path; an empty list when it fits.
	 *
	 * @return string[]
	 */
	public function validate(mixed $data, string $entity): array {
		$errors = [];
		$this->check($data, ['$ref' => $entity], '$', $entity, $errors);

		return $errors;
	}

	/** The schema of one entity, as written in its file. */
	public function schema(string $entity): array {
		if (!array_key_exists($entity, $this->schemas)) {
			$file = $this->directory . '/' . $entity . '.json';
			if (preg_match('/^[A-Za-z0-9]+$/', $entity) !== 1 || !is_file($file)) {
				throw new InvalidArgumentException('no schema for entity ' . $entity);
			}

			$schema = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($schema)) {
				throw new InvalidArgumentException('schema ' . $entity . ' is not an object');
			}

			$this->schemas[$entity] = $schema;
		}

		return $this->schemas[$entity];
	}

	/**
	 * @param string $file the entity whose file `$schema` is from, for local refs
	 * @param string[] $errors
	 */
	private function check(mixed $value, array $schema, string $path, string $file, array &$errors): void {
		if ($value === null && ($schema['nullable'] ?? false) === true) {
			return;
		}

		if (isset($schema['$ref'])) {
			[$file, $schema] = $this->resolve((string)$schema['$ref'], $file);
		}

		foreach ($schema['allOf'] ?? [] as $part) {
			$this->check($value, $part, $path, $file, $errors);
		}

		if (isset($schema['anyOf'])) {
			$closest = null;
			foreach ($schema['anyOf'] as $option) {
				$attempt = [];
				$this->check($value, $option, $path, $file, $attempt);
				if ($attempt === []) {
					$closest = [];
					break;
				}
				if ($closest === null || count($attempt) < count($closest)) {
					$closest = $attempt;
				}
			}
			if ($closest !== null && $closest !== []) {
				$errors[] = $path . ': matches none of the ' . count($schema['anyOf']) . ' allowed shapes, closest:';
				array_push($errors, ...$closest);
			}
		}

		if (isset($schema['type'])) {
			$types = (array)$schema['type'];
			$actual = $this->typeOf($value);
			if (!in_array($actual, $types, true) && !($actual === 'integer' && in_array('number', $types, true))) {
				$errors[] = $path . ': expected ' . implode('|', $types) . ', got ' . $actual
					. $this->sample($value);

				return;
			}
		}

		if ($value === null) {
			return;
		}

		if (array_key_exists('const', $schema) && $value !== $schema['const']) {
			$errors[] = $path . ': expected ' . json_encode($schema['const']) . $this->sample($value);
		}

		if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
			$errors[] = $path . ': ' . json_encode($value) . ' is not one of ' . json_encode($schema['enum']);
		}

		if (is_string($value)) {
			$this->checkString($value, $schema, $path, $errors);
		}

		if ((is_int($value) || is_float($value)) && isset($schema['minimum']) && $value < $schema['minimum']) {
			$errors[] = $path . ': ' . $value . ' is below the minimum ' . $schema['minimum'];
		}

		if (is_array($value)) {
			if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
				$errors[] = $path . ': expected at least ' . $schema['minItems'] . ' items, got ' . count($value);
			}
			if (isset($schema['items'])) {
				foreach ($value as $index => $item) {
					$this->check($item, $schema['items'], $path . '[' . $index . ']', $file, $errors);
				}
			}
		}

		if ($value instanceof stdClass) {
			$this->checkObject($value, $schema, $path, $file, $errors);
		}
	}

	/** @param string[] $errors */
	private function checkObject(stdClass $value, array $schema, string $path, string $file, array &$errors): void {
		$properties = $schema['properties'] ?? [];
		$present = get_object_vars($value);

		foreach ($schema['required'] ?? [] as $key) {
			if (!array_key_exists($key, $present)) {
				$errors[] = $path . ': missing required key "' . $key . '"';
			}
		}

		foreach ($present as $key => $item) {
			$key = (string)$key;
			if (array_key_exists($key, $properties)) {
				$this->check($item, $properties[$key], $path . '.' . $key, $file, $errors);
				continue;
			}

			$additional = $schema['additionalProperties'] ?? true;
			if ($additional === false) {
				$errors[] = $path . ': unexpected key "' . $key . '"';
			} elseif (is_array($additional)) {
				$this->check($item, $additional, $path . '.' . $key, $file, $errors);
			}
		}
	}

	/** @param string[] $errors */
	private function checkString(string $value, array $schema, string $path, array &$errors): void {
		if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
			$errors[] = $path . ': shorter than ' . $schema['minLength'] . ' characters' . $this->sample($value);
		}

		if (isset($schema['pattern']) && preg_match('~' . str_replace('~', '\~', $schema['pattern']) . '~u', $value) !== 1) {
			$errors[] = $path . ': does not match /' . $schema['pattern'] . '/' . $this->sample($value);
		}

		if (isset($schema['format']) && !$this->matchesFormat($value, (string)$schema['format'])) {
			$errors[] = $path . ': not a valid ' . $schema['format'] . $this->sample($value);
		}
	}

	private function matchesFormat(string $value, string $format): bool {
		switch ($format) {
			case 'datetime':
				if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)\.\d{3}Z$/', $value, $m) !== 1) {
					return false;
				}

				return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
			case 'iso8601':
				return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) === 1
					&& strtotime($value) !== false;
			case 'date':
				if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
					return false;
				}

				return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
			case 'id':
			case 'unix-string':
				return preg_match('/^\d+$/', $value) === 1;
			case 'url':
				$scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));

				return in_array($scheme, ['http', 'https'], true)
					&& (string)parse_url($value, PHP_URL_HOST) !== '';
			case 'language':
				return preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $value) === 1;
		}

		throw new InvalidArgumentException('unknown format ' . $format);
	}

	/**
	 * @return array{string, array} the file the schema is in, and the schema
	 */
	private function resolve(string $ref, string $file): array {
		$hash = strpos($ref, '#');
		$target = ($hash === false) ? $ref : substr($ref, 0, $hash);
		$pointer = ($hash === false) ? '' : substr($ref, $hash + 1);
		$target = ($target === '') ? $file : $target;

		$schema = $this->schema($target);
		if ($pointer !== '') {
			foreach (explode('/', ltrim($pointer, '/')) as $segment) {
				if (!is_array($schema) || !array_key_exists($segment, $schema)) {
					throw new InvalidArgumentException('unresolvable $ref ' . $ref . ' in ' . $file);
				}
				$schema = $schema[$segment];
			}
		}

		if (!is_array($schema)) {
			throw new InvalidArgumentException('$ref ' . $ref . ' does not name a schema');
		}

		return [$target, $schema];
	}

	private function typeOf(mixed $value): string {
		return match (true) {
			$value === null => 'null',
			is_bool($value) => 'boolean',
			is_int($value) => 'integer',
			is_float($value) => 'number',
			is_string($value) => 'string',
			is_array($value) => 'array',
			$value instanceof stdClass => 'object',
			default => get_debug_type($value),
		};
	}

	private function sample(mixed $value): string {
		$json = json_encode($value);
		if (!is_string($json)) {
			return '';
		}

		return ' (' . ((strlen($json) > 60) ? substr($json, 0, 57) . '...' : $json) . ')';
	}
}
