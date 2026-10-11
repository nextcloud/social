<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tools\Exceptions\RowNotFoundException;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The instance the golden-file tests run on: `https://cloud.example` with the
 * app at `/apps/social/`, a local account `alice`, and `bob` on a remote
 * Mastodon-like server.
 *
 * What is real here is everything that decides what a document looks like:
 * the configuration service, the URL routes (read off the controllers'
 * route attributes, so a renamed route shows up in the goldens), the actor
 * row parsing, the signing, and the ActivityService that wraps and queues an
 * activity. What is replaced is the database and the network: the delivery
 * queue records what it is handed instead of writing it.
 */
trait TFederationWorld {
	use TBuildsServices;

	protected static string $cloud = 'https://cloud.example';
	protected static string $social = 'https://cloud.example/apps/social/';
	protected static string $bob = 'https://remote.example/users/bob';

	private static ?string $worldPrivateKey = null;
	private static ?string $worldPublicKey = null;

	/** @var array<string, string> app config values, by key */
	protected array $appValues = [];

	/** @var list<string> every activity the delivery queue was handed, as queued */
	protected array $queued = [];

	protected ?ConfigService $worldConfig = null;
	protected ?IURLGenerator $worldUrls = null;

	protected static function worldKeys(): array {
		if (self::$worldPrivateKey === null) {
			$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			openssl_pkey_export($key, $private);
			self::$worldPrivateKey = $private;
			self::$worldPublicKey = openssl_pkey_get_details($key)['key'];
		}

		return [(string)self::$worldPrivateKey, (string)self::$worldPublicKey];
	}

	protected function config(): ConfigService {
		if ($this->worldConfig !== null) {
			return $this->worldConfig;
		}

		$this->appValues += [
			ConfigService::CLOUD_URL => self::$cloud,
			ConfigService::SOCIAL_URL => self::$social,
		];
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->appValues[$key] ?? $default
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => isset($this->appValues[$key])
				? $this->appValues[$key] === '1' : $default
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => isset($this->appValues[$key])
				? (int)$this->appValues[$key] : $default
		);

		return $this->worldConfig = new ConfigService(
			'alice',
			$appConfig,
			$this->createStub(IUserConfig::class),
			$this->createStub(IConfig::class),
			$this->urls(),
		);
	}

	/**
	 * Absolute URLs for the app's routes, built from the `FrontpageRoute`
	 * attribute on the controller method the route names; arguments the path
	 * does not use become the query string, as the server's router does.
	 */
	protected function urls(): IURLGenerator {
		if ($this->worldUrls !== null) {
			return $this->worldUrls;
		}

		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args = []): string => self::routeUrl($route, $args)
		);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $args = []): string => (string)parse_url(self::routeUrl($route, $args), PHP_URL_PATH)
		);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => self::$cloud . '/' . ltrim($path, '/')
		);
		$urls->method('imagePath')->willReturnCallback(
			static fn (string $app, string $image): string => '/apps/' . $app . '/img/' . $image
		);

		return $this->worldUrls = $urls;
	}

	protected static function routeUrl(string $route, array $args): string {
		$parts = explode('.', $route);
		$path = '/' . $route;
		if (count($parts) === 3 && $parts[0] === 'social') {
			$class = 'OCA\\Social\\Controller\\' . $parts[1] . 'Controller';
			if (method_exists($class, $parts[2])) {
				foreach ((new ReflectionMethod($class, $parts[2]))->getAttributes(FrontpageRoute::class) as $attribute) {
					$arguments = $attribute->getArguments();
					if (($arguments['verb'] ?? 'GET') === 'GET') {
						$path = (string)($arguments['url'] ?? $path);
						break;
					}
				}
			}
		}

		$query = [];
		foreach ($args as $name => $value) {
			if (str_contains($path, '{' . $name . '}')) {
				$path = str_replace('{' . $name . '}', (string)$value, $path);
			} else {
				$query[$name] = $value;
			}
		}

		return rtrim(self::$social, '/') . $path . ($query === [] ? '' : '?' . http_build_query($query));
	}

	protected function signatureService(): SignatureService {
		return $this->realService(SignatureService::class, [
			ConfigService::class => $this->config(),
			'logger' => new NullLogger(),
		]);
	}

	/**
	 * The real ActivityService, with a delivery queue that records the bytes
	 * it is handed — exactly what would have been POSTed to each inbox.
	 */
	protected function activityService(array $overrides = []): ActivityService {
		$queue = $this->createStub(RequestQueueService::class);
		$queue->method('generateRequestQueue')->willReturnCallback(
			function (array $paths, ACore $item): string {
				$this->queued[] = (string)json_encode($item, JSON_UNESCAPED_SLASHES);

				return '';
			}
		);

		return $this->realService(ActivityService::class, $overrides + [
			RequestQueueService::class => $queue,
			SignatureService::class => $this->signatureService(),
			ConfigService::class => $this->config(),
			'logger' => new NullLogger(),
		]);
	}

	/** The one activity queued since the last call, as an array. */
	protected function lastQueued(): array {
		$this->assertNotSame([], $this->queued, 'nothing was queued for delivery');
		$last = end($this->queued);
		$this->queued = [];

		$decoded = json_decode($last, true);
		$this->assertIsArray($decoded);

		return $decoded;
	}

	/**
	 * A local actor as the app serves it: the `social_actors` row parsed by
	 * the real ActorsRequest, cached the way ActorService::cacheLocalActor()
	 * caches it (its own document as `source`), and read back out of the
	 * cache row by the real CacheActorsRequest — the copy every actor
	 * document, WebFinger answer and timeline is served from.
	 *
	 * @param array<string, mixed> $row columns of `social_actors`
	 * @param ?callable(Person): void $beforeCache what AccountService adds
	 *                                             before it caches the actor
	 */
	protected function localActor(array $row = [], ?callable $beforeCache = null): Person {
		[$private, $public] = self::worldKeys();
		$row += [
			'id' => 'aliceid',
			'user_id' => 'alice',
			'preferred_username' => 'alice',
			'name' => 'Alice',
			'summary' => 'Gardener & sometime <coder>.',
			'public_key' => $public,
			'private_key' => '',
			'creation' => '2025-01-02 03:04:05',
			'locked' => 0,
			'discoverable' => 1,
			'indexable' => 1,
			'bot' => 0,
		];

		/** @var ActorsRequest $actorsRequest */
		$actorsRequest = $this->realService(ActorsRequest::class, [
			ConfigService::class => $this->config(),
			IURLGenerator::class => $this->urls(),
			'logger' => new NullLogger(),
		]);
		$actor = $actorsRequest->parseActorsSelectSql($row);
		$actor->setPrivateKey($private);
		if ($beforeCache !== null) {
			$beforeCache($actor);
		}

		$actor->setLocal(true);
		$actor->setSource((string)json_encode($actor, JSON_UNESCAPED_SLASHES));

		$cached = $this->fromCacheRow($this->cacheRow($actor));
		$cached->setPrivateKey($private);
		$cached->setDisplayW3ContextSecurity(true);

		return $cached;
	}

	/**
	 * The columns CacheActorsRequest::save() writes for an actor.
	 *
	 * @return array<string, mixed>
	 */
	protected function cacheRow(Person $actor): array {
		return [
			'id' => $actor->getId(),
			'account' => $actor->getAccount(),
			'type' => $actor->getType(),
			'local' => $actor->isLocal() ? '1' : '0',
			'following' => $actor->getFollowing(),
			'followers' => $actor->getFollowers(),
			'inbox' => $actor->getInbox(),
			'shared_inbox' => $actor->getSharedInbox(),
			'outbox' => $actor->getOutbox(),
			'featured' => $actor->getFeatured(),
			'url' => $actor->getUrl(),
			'preferred_username' => $actor->getPreferredUsername(),
			'name' => $actor->getName(),
			'summary' => $actor->getSummary(),
			'public_key' => $actor->getPublicKey(),
			'source' => $actor->getSource(),
			'details' => json_encode($actor->getDetailsAll()),
			'creation' => '2025-01-02 03:04:05',
		];
	}

	/**
	 * A `social_cache_actors` row read the way CacheActorsRequest reads one,
	 * with no icon joined in.
	 *
	 * @param array<string, mixed> $row
	 */
	protected function fromCacheRow(array $row, int $format = ACore::FORMAT_ACTIVITYPUB): Person {
		// the moved-to target is looked up in the cache table; here it is
		// never cached, which leaves the model to derive it from the id
		$cacheActorsRequest = new class(...$this->serviceArguments(CacheActorsRequest::class, [ ConfigService::class => $this->config(), IURLGenerator::class => $this->urls(), 'logger' => new NullLogger(), ])) extends CacheActorsRequest {
			#[\Override]
			protected function cachedMovedTarget(string $id): Person {
				throw new RowNotFoundException();
			}
		};

		$qb = $this->createStub(SocialQueryBuilder::class);
		$qb->method('getFormat')->willReturn($format);
		$qb->method('parseLeftJoinCacheDocuments')->willThrowException(new InvalidResourceException());
		$qb->method('hasViewer')->willReturn(false);

		return $cacheActorsRequest->parseCacheActorsSelectSql($row, $qb);
	}

	/** Bob, cached as a remote Mastodon account, the way a fetched actor is stored. */
	protected function bob(): Person {
		$bob = new Person();
		$bob->setId(self::$bob);
		$bob->setPreferredUsername('bob');
		$bob->setName('Bob');
		$bob->setAccount('bob@remote.example');
		$bob->setInbox(self::$bob . '/inbox');
		$bob->setSharedInbox('https://remote.example/inbox');
		$bob->setOutbox(self::$bob . '/outbox');
		$bob->setFollowers(self::$bob . '/followers');
		$bob->setFollowing(self::$bob . '/following');
		$bob->setFeatured(self::$bob . '/collections/featured');
		$bob->setUrl('https://remote.example/@bob');

		return $bob;
	}
}
