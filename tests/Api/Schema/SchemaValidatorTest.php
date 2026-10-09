<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Api\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The validator the entity tests stand on. A validator that accepts too much
 * turns every schema test into a tautology, so what it must *refuse* is pinned
 * here, one rule at a time, against a small schema of the test's own.
 */
class SchemaValidatorTest extends TestCase {
	private string $directory = '';
	private SchemaValidator $validator;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/social-schema-' . bin2hex(random_bytes(4));
		mkdir($this->directory);

		$this->write('Thing', [
			'type' => 'object',
			'required' => ['id', 'created_at', 'count', 'note', 'tags', 'owner'],
			'properties' => [
				'id' => ['type' => 'string', 'format' => 'id'],
				'created_at' => ['type' => 'string', 'format' => 'datetime'],
				'count' => ['type' => 'integer', 'minimum' => 0],
				'note' => ['type' => ['string', 'null']],
				'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
				'tags' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/Tag']],
				'owner' => ['$ref' => 'Owner', 'nullable' => true],
				'meta' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['x' => ['type' => 'number']]],
				'either' => ['anyOf' => [['type' => 'integer'], ['type' => 'string', 'pattern' => '^[a-z]+:[a-z]+$']]],
			],
			'$defs' => [
				'Tag' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'minLength' => 1]]],
			],
		]);
		$this->write('Owner', [
			'type' => 'object',
			'required' => ['url'],
			'properties' => ['url' => ['type' => 'string', 'format' => 'url']],
		]);

		$this->validator = new SchemaValidator($this->directory);
	}

	protected function tearDown(): void {
		foreach (glob($this->directory . '/*.json') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->directory);
	}

	private function write(string $entity, array $schema): void {
		file_put_contents($this->directory . '/' . $entity . '.json', json_encode($schema));
	}

	/** A valid Thing, changed by `$change`, as a client would decode it. */
	private function thing(array $change = [], array $remove = []): mixed {
		$thing = array_merge([
			'id' => '109347',
			'created_at' => '2026-10-09T12:34:56.789Z',
			'count' => 3,
			'note' => null,
			'tags' => [['name' => 'cats']],
			'owner' => ['url' => 'https://example.org/@owner'],
		], $change);
		foreach ($remove as $key) {
			unset($thing[$key]);
		}

		return SchemaValidator::wire($thing);
	}

	public function testAValidPayloadHasNoErrors(): void {
		$this->assertSame([], $this->validator->validate($this->thing(), 'Thing'));
	}

	/** @return array<string, array{array, string}> */
	public static function departures(): array {
		return [
			'an integer id' => [['id' => 109347], '$.id: expected string, got integer'],
			'an id that is not digits' => [['id' => 'abc'], '$.id: not a valid id'],
			'a date without milliseconds' => [['created_at' => '2026-10-09T12:34:56Z'], '$.created_at: not a valid datetime'],
			'a date with an offset' => [['created_at' => '2026-10-09T12:34:56.000+00:00'], '$.created_at: not a valid datetime'],
			'a date that does not exist' => [['created_at' => '2026-02-30T12:34:56.000Z'], '$.created_at: not a valid datetime'],
			'a count as a string' => [['count' => '3'], '$.count: expected integer, got string'],
			'a count as a float' => [['count' => 3.5], '$.count: expected integer, got number'],
			'a negative count' => [['count' => -1], '$.count: -1 is below the minimum 0'],
			'an unknown enum value' => [['kind' => 'c'], '$.kind: "c" is not one of ["a","b"]'],
			'an object where a list belongs' => [['tags' => ['first' => ['name' => 'x']]], '$.tags: expected array, got object'],
			'an empty name inside a list' => [['tags' => [['name' => '']]], '$.tags[0].name: shorter than 1 characters'],
			'a nested entity with a bad url' => [['owner' => ['url' => 'javascript:alert(1)']], '$.owner.url: not a valid url'],
			'a key a closed object does not allow' => [['meta' => ['x' => 1, 'y' => 2]], '$.meta: unexpected key "y"'],
			'a value no alternative accepts' => [['either' => 'plain'], '$.either: matches none of the 2 allowed shapes, closest:'],
		];
	}

	#[DataProvider('departures')]
	public function testEachDepartureIsReportedWithItsPath(array $change, string $expected): void {
		$errors = $this->validator->validate($this->thing($change), 'Thing');

		$this->assertNotSame([], $errors);
		$this->assertStringStartsWith($expected, $errors[0], implode("\n", $errors));
	}

	/**
	 * Mastodon's rule is that a nullable key is still sent: a client that
	 * declares it non-optional fails on a missing one, but not on `null`.
	 */
	public function testAMissingRequiredKeyIsNotTheSameAsANullOne(): void {
		$this->assertSame([], $this->validator->validate($this->thing(['owner' => null]), 'Thing'));
		$this->assertSame(
			['$: missing required key "owner"'],
			$this->validator->validate($this->thing([], ['owner']), 'Thing')
		);
		$this->assertSame(
			['$: missing required key "note"'],
			$this->validator->validate($this->thing([], ['note']), 'Thing')
		);
	}

	public function testAnEmptyObjectIsNotAnEmptyList(): void {
		$errors = $this->validator->validate(SchemaValidator::wire(['tags' => new \stdClass()]), 'Thing');

		$this->assertContains('$.tags: expected array, got object ({})', $errors);
	}

	public function testANamespacedAlternativeIsAccepted(): void {
		$this->assertSame([], $this->validator->validate($this->thing(['either' => 'story:react']), 'Thing'));
		$this->assertSame([], $this->validator->validate($this->thing(['either' => 7]), 'Thing'));
	}

	/**
	 * Every schema the entity tests use parses, and every `$ref` in it names
	 * something that exists: a typo there would otherwise surface as an
	 * exception in whichever test first reached that branch.
	 */
	public function testEveryShippedSchemaResolves(): void {
		$validator = new SchemaValidator();
		$entities = $validator->entities();
		$this->assertGreaterThan(30, count($entities));

		foreach ($entities as $entity) {
			$schema = $validator->schema($entity);
			$this->assertArrayHasKey('description', $schema, $entity . ' says what it is and where it comes from');
			foreach ($this->refsIn($schema) as $ref) {
				// validating null walks into the reference without needing a payload
				$validator->validate(null, ($ref[0] === '#') ? $entity . $ref : $ref);
			}
		}
	}

	/** @return string[] */
	private function refsIn(array $schema): array {
		$refs = [];
		array_walk_recursive($schema, static function (mixed $value, string|int $key) use (&$refs): void {
			if ($key === '$ref') {
				$refs[] = (string)$value;
			}
		});

		return $refs;
	}
}
