<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AppInfo;

use OCA\Social\Tests\Integration\Api\VisibilityMatrix;
use OCP\AppFramework\Http\Attribute\Route;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Every route that serves posts is a read path of the visibility matrix, or
 * is named with the reason it is not.
 *
 * The integration suite's `VisibilityMatrixTest` asks each read path what it
 * shows of every kind of post to every kind of viewer. That catches a filter
 * a path forgets only for the paths it knows, so a route added later has to
 * be added there too: this walks each GET route's code, and the helpers of
 * its own class it calls, for a call that reads posts, and fails for a route
 * that does so and is in neither `VisibilityMatrix::ROUTES` nor
 * `VisibilityMatrix::ROUTES_OUTSIDE`. The dashboard widgets, the unified
 * search providers and the reference providers are read paths outside the
 * router, and are held to the same.
 */
class VisibilityMatrixRoutesTest extends TestCase {
	/** calls that read posts, whatever they are called on */
	private const READS = [
		'getTimeline', 'getStreamByNid', 'getStreamById', 'getStreamByObjectId', 'getContextByNid',
		'visiblePosts', 'quotesOf', 'attachCards', 'attachPlaces', 'searchStreamContent',
		'resolveStatus', 'trendingStatuses', 'getRepliesCollection', 'getRepliesPage', 'getRatesCollection',
	];

	/** services whose every call reads posts, but for telling them who is reading */
	private const READERS = ['StreamService', 'StreamRequest', 'ConversationService', 'NotificationInboxService'];
	private const NOT_READS = ['setViewer', 'resetViewer', 'getViewerId', 'lastTimelineRowCount', 'newestHomeNid'];

	public function testEveryRouteThatServesPostsIsAPathOfTheMatrixOrNamedWithAReason(): void {
		$serving = [];
		foreach (self::getRoutes(true) as $key => $method) {
			if (self::readsPosts($method)) {
				$serving[$key] = true;
			}
		}

		$this->assertGreaterThan(40, count($serving), 'the walk found the routes that serve posts');
		$unexplained = array_keys(array_diff_key($serving, VisibilityMatrix::ROUTES, VisibilityMatrix::ROUTES_OUTSIDE));
		$this->assertSame([], $unexplained, 'routes that serve posts, in neither VisibilityMatrix::ROUTES nor ROUTES_OUTSIDE');
	}

	public function testTheMatrixNamesOnlyRoutesThatExist(): void {
		$routes = self::getRoutes();

		$this->assertSame([], array_keys(array_diff_key(VisibilityMatrix::ROUTES, $routes)), 'VisibilityMatrix::ROUTES names a route that is gone');
		$this->assertSame([], array_keys(array_diff_key(VisibilityMatrix::ROUTES_OUTSIDE, $routes)), 'ROUTES_OUTSIDE names a route that is gone');
		$this->assertSame([], array_keys(array_intersect_key(VisibilityMatrix::ROUTES, VisibilityMatrix::ROUTES_OUTSIDE)), 'a route is both in the matrix and outside it');
		$this->assertSame(
			[], array_values(array_diff(VisibilityMatrix::ROUTES, array_keys(VisibilityMatrix::PATHS))),
			'VisibilityMatrix::ROUTES maps a route to a path the matrix does not run'
		);
	}

	/**
	 * A widget that lists posts, and a search or reference provider, read
	 * posts as somebody: each is a path of the matrix.
	 */
	public function testEveryWidgetAndProviderThatServesPostsIsAPathOfTheMatrix(): void {
		$found = [];
		foreach (['Dashboard', 'Search', 'Reference'] as $directory) {
			foreach (glob(__DIR__ . '/../../lib/' . $directory . '/*.php') ?: [] as $file) {
				$class = new ReflectionClass('OCA\\Social\\' . $directory . '\\' . basename($file, '.php'));
				if ($class->isAbstract() || $class->isInterface()) {
					continue;
				}
				foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
					if (self::readsPosts($method)) {
						$found[$class->getShortName()] = true;
						break;
					}
				}
			}
		}

		$this->assertArrayHasKey('SocialTimelineWidget', $found, 'the walk follows a widget into the base it inherits from');
		$this->assertSame(
			[], array_keys(array_diff_key($found, VisibilityMatrix::OUTSIDE_ROUTER)),
			'widgets and providers that serve posts, not in VisibilityMatrix::OUTSIDE_ROUTER'
		);
		$this->assertSame(
			[], array_values(array_diff(VisibilityMatrix::OUTSIDE_ROUTER, array_keys(VisibilityMatrix::PATHS))),
			'VisibilityMatrix::OUTSIDE_ROUTER maps a class to a path the matrix does not run'
		);
	}

	/** The walk sees a read through a helper of the class and through the API base. */
	public function testTheWalkFollowsHelpersOfTheClassAndItsBases(): void {
		$routes = self::getRoutes();

		$this->assertTrue(self::readsPosts($routes['TimelineApiController::timelines']));
		$this->assertTrue(self::readsPosts($routes['ConversationController::index']));
		$this->assertTrue(self::readsPosts($routes['ActivityPubController::displayPost']));
		$this->assertFalse(self::readsPosts($routes['AccountApiController::verifyCredentials']));
	}

	/**
	 * Every route, or every GET route, by `Controller::method`: the attribute
	 * routes and the ones `appinfo/routes.php` declares.
	 *
	 * @return array<string, ReflectionMethod>
	 */
	private static function getRoutes(bool $getOnly = false): array {
		$routes = [];
		foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') ?: [] as $file) {
			$class = new ReflectionClass('OCA\\Social\\Controller\\' . basename($file, '.php'));
			foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class->getName()) {
					continue;
				}
				foreach ($method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
					if (!$getOnly || strtoupper($attribute->newInstance()->getVerb()) === 'GET') {
						$routes[$class->getShortName() . '::' . $method->getName()] = $method;
					}
				}
			}
		}

		$declared = require __DIR__ . '/../../appinfo/routes.php';
		foreach ($declared['routes'] as $route) {
			if ($getOnly && strtoupper($route['verb'] ?? 'GET') !== 'GET') {
				continue;
			}
			[$controller, $name] = explode('#', $route['name']);
			$method = new ReflectionMethod('OCA\\Social\\Controller\\' . $controller . 'Controller', $name);
			$routes[$controller . 'Controller::' . $name] = $method;
		}

		return $routes;
	}

	/**
	 * Whether the method, or a method of its class or of a base it calls on
	 * `$this`, makes a call that reads posts.
	 */
	private static function readsPosts(ReflectionMethod $entry): bool {
		$class = $entry->getDeclaringClass();
		$visited = [];
		$pending = [$entry->getName()];
		while ($pending !== []) {
			$name = array_pop($pending);
			if (isset($visited[$name])) {
				continue;
			}
			$visited[$name] = true;

			$node = self::methodNode($class, $name);
			if ($node === null) {
				continue;
			}

			foreach ((new NodeFinder())->findInstanceOf($node, MethodCall::class) as $call) {
				if (!$call->name instanceof Node\Identifier) {
					continue;
				}
				$called = $call->name->toString();
				if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
					$pending[] = $called;
					continue;
				}
				if (in_array($called, self::READS, true)) {
					return true;
				}
				$receiver = self::receiverType($class, $call->var);
				if (in_array($receiver, self::READERS, true) && !in_array($called, self::NOT_READS, true)) {
					return true;
				}
			}
		}

		return false;
	}

	/** The short class name of a `$this->property` a call is made on, or ''. */
	private static function receiverType(ReflectionClass $class, Node\Expr $var): string {
		if (!$var instanceof Node\Expr\PropertyFetch || !$var->name instanceof Node\Identifier
			|| !$var->var instanceof Node\Expr\Variable || $var->var->name !== 'this') {
			return '';
		}

		$property = $var->name->toString();
		if (!$class->hasProperty($property)) {
			return '';
		}
		$type = $class->getProperty($property)->getType();
		if (!$type instanceof ReflectionNamedType) {
			return '';
		}

		$name = $type->getName();

		return substr($name, (int)strrpos($name, '\\') + (str_contains($name, '\\') ? 1 : 0));
	}

	/** A method of the class or of one of its bases, parsed. */
	private static function methodNode(ReflectionClass $class, string $name): ?ClassMethod {
		if (!$class->hasMethod($name)) {
			return null;
		}
		$declaring = $class->getMethod($name)->getDeclaringClass();
		$file = (string)$declaring->getFileName();
		if ($file === '' || !str_contains($file, '/lib/')) {
			return null;
		}

		return self::parsed($file)[$declaring->getName() . '::' . $name] ?? null;
	}

	/** @return array<string, ClassMethod> `Class::method` => node */
	private static function parsed(string $file): array {
		static $parsed = [];
		if (!isset($parsed[$file])) {
			$ast = (new NodeTraverser(new NameResolver()))->traverse(
				(new ParserFactory())->createForHostVersion()->parse((string)file_get_contents($file)) ?? []
			);
			$parsed[$file] = [];
			foreach ((new NodeFinder())->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $classLike) {
				$className = $classLike->namespacedName?->toString() ?? '';
				foreach ($classLike->getMethods() as $method) {
					$parsed[$file][$className . '::' . $method->name->toString()] = $method;
				}
			}
		}

		return $parsed[$file];
	}
}
