<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OCA\Social\Controller\AdminApiController;
use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\PlacesRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\Client\Collection;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Place;
use OCA\Social\Tests\Integration\QueryCounter;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Every `GET` route that takes `int $limit`, asked for more than it allows and
 * for less than one, with what reaches the database read off the SQL.
 *
 * A limit is the one parameter a client controls that decides how much the
 * server reads, and it is clamped route by route — `min(max($limit, 1), 40)`
 * here, `ProbeOptions::setLimit()` there. A route that forgets hands
 * `setMaxResults()` whatever it was sent. Over HTTP, Nextcloud 34's dispatcher
 * refuses a `limit` outside 1..500 before the action runs, so 500 is the most
 * a client can ask for, and that is the first case; 100000 and -1 are asked
 * of the action directly, for every caller that does not come through the
 * dispatcher — and -1 matters there, because SQLite reads `LIMIT -1` as no
 * limit at all.
 *
 * The routes are found by reflection, so a new one is in this test the day it
 * is written. Each is asked three times as the reference — at the largest page
 * it documents (80 unless listed below), at 1, and at its default — and then
 * out of range. Every `LIMIT` an out-of-range request produces must be one the
 * reference requests produced too: a clamped limit is indistinguishable from a
 * page of the maximum or the minimum, and an unclamped one is a `LIMIT` nobody
 * else asked for. Fixed internal limits (the five hundred followed
 * collections, the reactions of a page) are the same in every run and pass
 * through unremarked.
 */
class LimitClampTest extends ReaderTestCase {
	/** The page size a route may grow to where its documented maximum is above 80. */
	private const DOCUMENTED_MAX = [
		// each as docs/API.md states it
		'AdminApiController::accounts' => 200,
		'AdminApiController::reports' => 200,
		'AdminApiController::trendTags' => 100,
		'ListController::accounts' => 500,
		'NotificationController::unreadCountV2' => 399,
		'RelationController::domainBlocks' => 200,
	];

	/** Routes whose page is not read by a query here, each with what bounds it instead. */
	private const NOT_FROM_THE_DATABASE = [
		'AtprotoFeedsController::timeline' => 'the page is the Bluesky AppView\'s, asked over HTTP with the limit clamped to 1..100 (BlueskyFeeds::page)',
		'DiscoveryController::searchDirectories' => 'asks remote directory servers over HTTP, the limit clamped to 1..MAX_LIMIT (FediverseDirectoryService::search)',
		'DiscoveryController::searchHashtagDirectories' => 'asks peer instances over HTTP, the limit clamped to 1..MAX_LIMIT (PeerTrendService::tags)',
		'InstanceApiController::gifs' => 'a slice of the GIF pack\'s list, clamped to 1..200 in the controller',
	];

	private int $listId = 0;
	private ?Collection $collection = null;

	protected function tearDown(): void {
		if ($this->listId > 0) {
			$list = new MastodonList();
			$list->setId($this->listId);
			Server::get(ListsRequest::class)->delete($list);
			$this->listId = 0;
		}
		if ($this->collection !== null) {
			Server::get(CollectionsRequest::class)->delete($this->collection);
			$this->collection = null;
		}
		$admin = Server::get(IGroupManager::class)->get('admin');
		if ($admin !== null && $admin->inGroup($this->user())) {
			$admin->removeUser($this->user());
		}

		parent::tearDown();
	}

	/** @return iterable<string, array{class-string, string}> */
	public static function pagedRoutes(): iterable {
		foreach (glob(__DIR__ . '/../../../lib/Controller/*Controller.php') ?: [] as $file) {
			/** @var class-string $class */
			$class = 'OCA\\Social\\Controller\\' . basename($file, '.php');
			$reflection = new ReflectionClass($class);
			if ($reflection->isAbstract()) {
				continue;
			}
			foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || !self::takesALimit($method)) {
					continue;
				}
				foreach ($method->getAttributes(FrontpageRoute::class) as $route) {
					if (($route->getArguments()['verb'] ?? '') === 'GET') {
						yield $reflection->getShortName() . '::' . $method->getName() => [$class, $method->getName()];
						break;
					}
				}
			}
		}
	}

	private static function takesALimit(ReflectionMethod $method): bool {
		foreach ($method->getParameters() as $parameter) {
			$type = $parameter->getType();
			if ($parameter->getName() === 'limit' && $type instanceof ReflectionNamedType && $type->getName() === 'int') {
				return true;
			}
		}

		return false;
	}

	public function testTheSweepFindsTheRoutesItIsAbout(): void {
		$routes = iterator_to_array(self::pagedRoutes());

		$this->assertGreaterThan(40, count($routes), 'the reflection over the controllers stopped finding routes');
		foreach (array_keys(self::NOT_FROM_THE_DATABASE + self::DOCUMENTED_MAX) as $named) {
			$this->assertArrayHasKey($named, $routes, $named . ' is listed here but is no longer a paged GET route');
		}
	}

	/**
	 * @param class-string $class
	 */
	#[DataProvider('pagedRoutes')]
	public function testTheLimitReachingTheQueryIsClamped(string $class, string $method): void {
		$route = (new ReflectionClass($class))->getShortName() . '::' . $method;
		if (isset(self::NOT_FROM_THE_DATABASE[$route])) {
			$this->markTestSkipped(self::NOT_FROM_THE_DATABASE[$route]);
		}
		if ($class === AdminApiController::class) {
			Server::get(IGroupManager::class)->get('admin')?->addUser($this->user());
		}
		$arguments = $this->argumentsFor($class, $method);

		$maximum = $this->limitsOf($route, $class, $method, $arguments, self::DOCUMENTED_MAX[$route] ?? 80);
		$this->assertNotSame(
			[], $maximum,
			$route . ' ran no query with a LIMIT, so nothing here says its page is bounded; if its page is '
			. 'not read by a query, list it in NOT_FROM_THE_DATABASE with what bounds it'
		);
		$minimum = array_merge(
			$this->limitsOf($route, $class, $method, $arguments, 1),
			$this->limitsOf($route, $class, $method, $arguments, null),
		);

		foreach ([500, 100000] as $asked) {
			$this->assertSame(
				[], array_values(array_diff($this->limitsOf($route, $class, $method, $arguments, $asked), $maximum)),
				$route . ' with limit=' . $asked . ' reads more than with the largest page it documents'
			);
		}

		$negative = $this->limitsOf($route, $class, $method, $arguments, -1);
		$this->assertSame(
			[], array_values(array_filter($negative, static fn (int $limit): bool => $limit < 1)),
			$route . ' passes a limit below 1 to the database — LIMIT -1 is no limit at all on SQLite'
		);
		$this->assertSame(
			[], array_values(array_diff($negative, $minimum)),
			$route . ' with limit=-1 reads a page neither the smallest nor the default one is'
		);
	}

	/**
	 * Every LIMIT the route's queries carry, asked with `$limit` (null: the
	 * route's default), after checking it answered.
	 *
	 * @param list<mixed> $arguments
	 *
	 * @return list<int>
	 */
	private function limitsOf(string $route, string $class, string $method, array $arguments, ?int $limit): array {
		$reflection = new ReflectionMethod($class, $method);
		foreach ($reflection->getParameters() as $i => $parameter) {
			if ($parameter->getName() === 'limit') {
				$arguments[$i] = $limit ?? $parameter->getDefaultValue();
			}
		}

		$run = QueryCounter::record(function () use ($class, $reflection, $arguments): Response {
			$response = $reflection->invokeArgs($this->controller($class), $arguments);
			self::render($response);

			return $response;
		});
		/** @var Response $response */
		$response = $run->result();
		$this->assertSame(
			Http::STATUS_OK, $response->getStatus(),
			$route . ' with limit=' . ($limit ?? 'default') . ' answered ' . $response->getStatus() . ': ' . self::render($response)
		);

		preg_match_all('/\bLIMIT\s+(-?\d+)/i', implode("\n", array_column($run->queries(), 'sql')), $matches);

		return array_values(array_unique(array_map('intval', $matches[1])));
	}

	/**
	 * The route's arguments other than the limit: its defaults, and for the
	 * ones it needs, something this test seeded for it.
	 *
	 * @return list<mixed>
	 */
	private function argumentsFor(string $class, string $method): array {
		$viewer = $this->viewer();
		$author = $this->remoteActor('limits');
		$post = $this->post($author, 'a post tagged #perf');

		$values = [
			'account' => (string)$viewer->getNid(),
			'account_id' => (string)$viewer->getNid(),
			'hashtag' => 'perf',
			'nid' => (string)Server::get(StreamRequest::class)->getStreamById($post->getId())->getNid(),
			'q' => 'perf',
			'timeline' => 'home',
			'url' => 'https://news.example/an-article',
		];
		if (str_ends_with($class, '\\ListController')) {
			$list = new MastodonList();
			$list->setOwnerId($viewer->getId())->setTitle('limits');
			$this->listId = Server::get(ListsRequest::class)->create($list)->getId();
			$values['id'] = $this->listId;
		}
		if (str_ends_with($class, '\\PlaceController')) {
			// places are never deleted, so each run names its own
			$place = (new Place())->setName('Itestville ' . bin2hex(random_bytes(4)))->setCountry('DE');
			$values['id'] = Server::get(PlacesRequest::class)->findOrCreate($place)->getId();
		}
		if (str_ends_with($class, '\\CollectionController')) {
			$collection = new Collection();
			$collection->setOwnerId($viewer->getId())->setTitle('limits')->setVisibility(Collection::VISIBILITY_PUBLIC);
			$this->collection = Server::get(CollectionsRequest::class)->save($collection);
			$values['id'] = $this->collection->getId();
		}
		if (str_ends_with($class, '\\PlaceController')) {
			// places are never deleted; a later run is handed the same one
			$place = (new Place())->setName('Limitville')->setCountry('DE');
			$values['id'] = Server::get(PlacesRequest::class)->findOrCreate($place)->getId();
		}

		$arguments = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
			$arguments[] = match (true) {
				array_key_exists($parameter->getName(), $values) => $values[$parameter->getName()],
				$parameter->isOptional() => $parameter->getDefaultValue(),
				default => 1,
			};
		}

		return $arguments;
	}
}
