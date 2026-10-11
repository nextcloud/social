<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration;

use OCA\Social\AppInfo\Application;
use OCP\Server;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The app's services built afresh, the way the container builds them, with
 * some of their dependencies replaced — at any depth.
 *
 * The services a cron job takes hold the network edge (`CurlService`, the
 * server's HTTP client) several layers down, and the container hands out
 * one shared instance of each, built before any test could have a say. This
 * builds every class of the app it is asked for from its constructor,
 * taking the replacement wherever a parameter asks for a replaced type and
 * itself wherever one asks for a container — so a service that resolves
 * another lazily, to break a cycle, gets the offline one too. Everything
 * else comes from the app's container as usual.
 *
 * What it cannot reach is a service fetched with `Server::get()` inside a
 * method, or through `AP::instance()`: those are the shared ones. Tests that
 * use this seed hosts under `.invalid`, which cannot resolve, so a request
 * that slips past the replacement fails at once rather than going anywhere.
 */
final class OfflineContainer implements ContainerInterface {
	/** @var array<string, object> */
	private array $built = [];

	/** @var array<string, true> the classes being built right now */
	private array $building = [];

	/** The app's own container, which knows what the server's does not: its IAppData, its name. */
	private ContainerInterface $app;

	/** @param array<class-string, object> $replacements */
	public function __construct(
		private array $replacements,
	) {
		$this->app = Server::get(Application::class)->getContainer();
	}

	/**
	 * @template T of object
	 * @param class-string<T>|string $id
	 * @return T|mixed
	 */
	#[\Override]
	public function get(string $id): mixed {
		if (isset($this->replacements[$id])) {
			return $this->replacements[$id];
		}
		if (isset($this->built[$id])) {
			return $this->built[$id];
		}
		if (!str_starts_with($id, 'OCA\\Social\\') || !class_exists($id)) {
			return $this->app->get($id);
		}

		$class = new ReflectionClass($id);
		if (!$class->isInstantiable() || isset($this->building[$id])) {
			// an interface the app's registration maps, or a cycle the
			// container would refuse as well
			return $this->app->get($id);
		}

		$this->building[$id] = true;
		try {
			return $this->built[$id] = $this->construct($class);
		} finally {
			unset($this->building[$id]);
		}
	}

	/**
	 * @param ReflectionClass<object> $class
	 */
	private function construct(ReflectionClass $class): object {
		$arguments = [];
		foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
				if (!$parameter->isDefaultValueAvailable()) {
					// a scalar the app's registration supplies: the container's own instance
					return $this->app->get($class->getName());
				}
				$arguments[] = $parameter->getDefaultValue();
				continue;
			}

			$name = $type->getName();
			$arguments[] = ($name === ContainerInterface::class) ? $this : $this->get($name);
		}

		return $class->newInstanceArgs($arguments);
	}

	#[\Override]
	public function has(string $id): bool {
		return true;
	}
}
