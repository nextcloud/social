<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use ReflectionClass;
use ReflectionNamedType;

/**
 * Builds a real service with a test double for every collaborator the test
 * does not hand in, so the code that shapes a document runs as it does in
 * production and only storage and transport are replaced.
 *
 * An override is keyed by the parameter's class or by its name. A nullable
 * parameter that defaults to null (an optional bridge, say) stays null unless
 * overridden; a scalar takes its default.
 */
trait TBuildsServices {
	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @param array<string, mixed> $overrides
	 * @return T
	 */
	protected function realService(string $class, array $overrides = []): object {
		return new $class(...$this->serviceArguments($class, $overrides));
	}

	/**
	 * The constructor arguments realService() would pass, for a test that
	 * has to subclass the service.
	 *
	 * @param class-string $class
	 * @param array<string, mixed> $overrides
	 * @return list<mixed>
	 */
	protected function serviceArguments(string $class, array $overrides = []): array {
		$constructor = (new ReflectionClass($class))->getConstructor();
		if ($constructor === null) {
			return [];
		}

		$args = [];
		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			$typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : '';

			if (array_key_exists($parameter->getName(), $overrides)) {
				$args[] = $overrides[$parameter->getName()];
			} elseif ($typeName !== '' && array_key_exists($typeName, $overrides)) {
				$args[] = $overrides[$typeName];
			} elseif ($parameter->isDefaultValueAvailable() && ($parameter->getDefaultValue() === null || $type === null || $type->isBuiltin())) {
				$args[] = $parameter->getDefaultValue();
			} elseif ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
				$args[] = $this->createStub($typeName);
			} else {
				$args[] = match ($typeName) {
					'string' => '',
					'int' => 0,
					'bool' => false,
					'array' => [],
					default => null,
				};
			}
		}

		return $args;
	}
}
