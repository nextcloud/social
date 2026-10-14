<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A route's parameters are bound by name from the query string or the form,
 * so a parameter of the client API has to be spelled the way a Mastodon
 * client spells it: `account_id`, never `$accountId`, which no client sends
 * and which therefore stays at its default on every request.
 */
class ClientApiParameterNamesTest extends TestCase {
	/** @return iterable<string, array{string, string, string}> */
	public static function routeParameters(): iterable {
		foreach (glob(__DIR__ . '/../../lib/Controller/*Api*.php') ?: [] as $file) {
			$class = 'OCA\\Social\\Controller\\' . basename($file, '.php');
			if (!class_exists($class)) {
				continue;
			}

			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->getAttributes(FrontpageRoute::class) === []) {
					continue;
				}

				foreach ($method->getParameters() as $parameter) {
					$name = $method->getName() . ' $' . $parameter->getName();
					yield basename($file, '.php') . '::' . $name => [$class, $method->getName(), $parameter->getName()];
				}
			}
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('routeParameters')]
	public function testARouteParameterIsSpelledTheWayAClientSendsIt(string $class, string $method, string $parameter): void {
		$this->assertMatchesRegularExpression(
			'/^[a-z0-9]+(_[a-z0-9]+)*$/',
			$parameter,
			$class . '::' . $method . '(): $' . $parameter . ' is not snake_case, so a client never binds it'
		);
	}
}
