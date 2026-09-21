<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCP\IDBConnection;
use OCP\Migration\IMigrationStep;
use OCP\Migration\IOutput;

/**
 * Runs the app's migration steps against a {@see FakeSchema}, in the order the
 * server runs them: by version, which for these is the filename.
 */
final class MigrationReplay {
	/**
	 * @param string[] $without classes to leave out
	 *
	 * @return string[] every migration class, in the order they run
	 */
	public static function steps(array $without = []): array {
		$files = glob(dirname(__DIR__, 2) . '/lib/Migration/Version*.php') ?: [];
		sort($files);

		$classes = array_map(
			static fn (string $path): string => 'OCA\\Social\\Migration\\' . basename($path, '.php'),
			$files
		);

		return array_values(array_diff($classes, $without));
	}

	/**
	 * @param string[] $classes the steps to run, in order
	 * @param array<string, object> $stand what to construct a step with, by type
	 */
	public static function run(array $classes, array $stand = [], ?FakeSchema $schema = null): FakeSchema {
		$schema ??= new FakeSchema();
		$output = new class implements IOutput {
			public function debug(string $message): void {
			}
			public function info($message): void {
			}
			public function warning($message): void {
			}
			public function startProgress($max = 0): void {
			}
			public function advance($step = 1, $description = ''): void {
			}
			public function finishProgress(): void {
			}
		};

		foreach ($classes as $class) {
			// only the schema half: `preSchemaChange()` and `postSchemaChange()`
			// read and write rows, which is a different question from what
			// shape the table ends up in
			self::instantiate($class, $stand)->changeSchema($output, static fn (): FakeSchema => $schema, []);
		}

		return $schema;
	}

	/**
	 * @param array<string, object> $stand what to construct a step with, by type
	 * @param string[] $without classes to leave out
	 */
	public static function everything(array $stand = [], array $without = []): FakeSchema {
		return self::run(self::steps($without), $stand);
	}

	/**
	 * A step, with something of the right type in each constructor argument.
	 *
	 * Only the schema half is replayed, so nothing a step was constructed with
	 * is ever called. `IDBConnection` is the only interface any of them asks
	 * for, and {@see FakeConnection} already stands in for it — the unit suite
	 * runs against the `nextcloud/ocp` interfaces alone, where a generated
	 * double for it cannot be built.
	 */
	/** @param array<string, object> $stand */
	private static function instantiate(string $class, array $stand = []): IMigrationStep {
		$reflection = new \ReflectionClass($class);
		$constructor = $reflection->getConstructor();
		if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
			return $reflection->newInstance();
		}

		$arguments = [];
		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

			if (isset($stand[$name])) {
				$arguments[] = $stand[$name];
				continue;
			}

			if ($name === IDBConnection::class) {
				$arguments[] = new FakeConnection();
				continue;
			}

			if ($name === '' || $type->isBuiltin()) {
				$arguments[] = null;
				continue;
			}

			$dependency = new \ReflectionClass($name);
			if ($dependency->isInterface() || $dependency->isAbstract()) {
				throw new \LogicException(
					$class . ' is constructed with ' . $name . ', which this replay has no stand-in for'
				);
			}

			$arguments[] = $dependency->newInstanceWithoutConstructor();
		}

		return $reflection->newInstanceArgs($arguments);
	}
}
