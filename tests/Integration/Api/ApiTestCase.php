<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OC\AppFramework\DependencyInjection\DIContainer;
use OC\AppFramework\Http\Request;
use OC\Authentication\Token\IProvider as TokenProvider;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\Social\AP;
use OCA\Social\Controller\AccountApiController;
use OCA\Social\Controller\StatusApiController;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\ClientRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\IRecommendationSignalEvents;
use OCA\Social\Service\IRecommendationSignalRecorder;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\RecommendationSignalService;
use OCA\Social\Tests\Api\Schema\SchemaValidator;
use OCA\Social\Tests\Integration\Helper\FreezesTheClock;
use OCP\AppFramework\Http\Response;
use OCP\Authentication\Token\IToken;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\ISecureRandom;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Calls the Mastodon client API the way a client does — a bearer token, the
 * query string, the route's own parameters — against the real server and
 * database, and hands back what would have gone onto the wire.
 *
 * The controllers are built with every service the container gives them and a
 * request of the test's own making, and the answer is put through
 * `json_encode()` exactly as Nextcloud's JSON responder does, then decoded
 * with objects kept as objects: a schema can then tell `{}` from `[]`, an
 * integer from a numeric string, and a key that is `null` from one that is
 * not there.
 *
 * The accounts are real Nextcloud users with real Social actors, made through
 * `AccountService::createActor()` — the path the setup screen takes — and
 * removed again with everything they wrote, so the suite is safe to run on an
 * instance that holds other data. Their names all start with `apictr-`.
 */
abstract class ApiTestCase extends TestCase {
	use FreezesTheClock;

	protected const PREFIX = 'apictr-';
	private const APP = 'apictr-client';
	/** every scope a client may ask for; the admin routes still check that the account is an administrator */
	private const SCOPES = ['read', 'write', 'follow', 'push', 'admin:read', 'admin:write'];

	/** @var array<string, Person> local name => actor */
	private array $accounts = [];

	/** @var array<string, string> local name => bearer token */
	private array $tokens = [];

	/** @var array<string, true> local names `call()` signs in without making their account */
	private array $withoutAccount = [];

	private int $clientId = 0;

	/** @var array<string, string> scope list => bearer token, for `scopedToken()` */
	private array $scopedTokens = [];

	protected SchemaValidator $schemas;

	/** the app container the server booted with, which each call replaces */
	private ?DIContainer $bootContainer = null;

	protected function setUp(): void {
		parent::setUp();
		$this->bootContainer = \OC::$server->getRegisteredAppContainer('social');
		$this->schemas = new SchemaValidator();
		Stream::resetReplyParentCache();
		Stream::resetQuoteCache();

		foreach ($this->accountNames() as $name) {
			$this->removeAccount($name);
		}
		$this->removeClient();
	}

	protected function tearDown(): void {
		foreach ($this->accountNames() as $name) {
			$this->removeAccount($name);
		}
		$this->removeClient();
		$this->accounts = [];
		$this->tokens = [];
		$this->withoutAccount = [];
		$this->scopedTokens = [];
		Stream::resetReplyParentCache();
		Stream::resetQuoteCache();
		$this->thawClock();
		// what comes after this test must not resolve the app's services
		// from a container that hands out this test's last request
		if ($this->bootContainer !== null) {
			\OC::$server->registerAppContainer('social', $this->bootContainer);
		}
		parent::tearDown();
	}

	/**
	 * Every account name the test may create, so that setUp can also remove
	 * what an aborted run left behind.
	 *
	 * @return string[]
	 */
	protected function accountNames(): array {
		return ['alice', 'bob', 'carol'];
	}

	/** A local account, created on first use, with its actor and cached copy. */
	protected function account(string $name): Person {
		if (!isset($this->accounts[$name])) {
			$userId = self::PREFIX . $name;
			$users = Server::get(IUserManager::class);
			if (!$users->userExists($userId)) {
				$users->createUser($userId, 'Api-Contract-' . bin2hex(random_bytes(12)));
			}

			Server::get(AccountService::class)->createActor($userId, $userId);
			$this->accounts[$name] = $this->cached($name);
		}

		return $this->accounts[$name];
	}

	/**
	 * A Nextcloud user who has not set up their account here: `call()` signs
	 * them in as they are, and whatever account they get, they make through
	 * the API.
	 */
	protected function userWithoutAccount(string $name): void {
		$userId = self::PREFIX . $name;
		$users = Server::get(IUserManager::class);
		if (!$users->userExists($userId)) {
			$users->createUser($userId, 'Api-Contract-' . bin2hex(random_bytes(12)));
		}
		$this->withoutAccount[$name] = true;
	}

	/** The account as it is cached now, with today's counts. */
	protected function cached(string $name): Person {
		return Server::get(AccountService::class)->getCachedLocalActor(self::PREFIX . $name);
	}

	/**
	 * Lets every notification through, so the list shows what happened: an
	 * account made here starts with a policy that holds back strangers and
	 * new accounts, which every account in a test is.
	 */
	protected function acceptEveryNotification(string $name): void {
		$this->account($name);
		Server::get(NotificationPolicyService::class)->save(self::PREFIX . $name, [
			'for_not_following' => 'accept',
			'for_not_followers' => 'accept',
			'for_new_accounts' => 'accept',
			'for_private_mentions' => 'accept',
			'for_limited_accounts' => 'accept',
		]);
	}

	/**
	 * Publishes a status through `POST /api/v1/statuses`.
	 *
	 * @param array<string, mixed> $params the form a client sends
	 */
	protected function publish(string $as, array $params): \stdClass {
		$response = $this->call(StatusApiController::class, 'statusNew', [], $params, $as, 'POST');
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
		$this->assertInstanceOf(\stdClass::class, $response->body);

		return $response->body;
	}

	/** `POST /api/v1/statuses/{id}/{act}`: favourite, reblog, bookmark, pin… */
	protected function act(string $as, string $statusId, string $act): \stdClass {
		$response = $this->call(StatusApiController::class, 'statusAction', ['nid' => $statusId, 'act' => $act], [], $as, 'POST');
		$this->assertSame(200, $response->status, $act . ': ' . (json_encode($response->body) ?: ''));

		return $response->body;
	}

	/** `POST /api/v1/accounts/{id}/follow`; between local accounts it is accepted at once. */
	protected function follow(string $as, string $whom): void {
		$response = $this->call(
			AccountApiController::class, 'accountFollow', ['id' => (string)$this->account($whom)->getNid()], [], $as, 'POST'
		);
		$this->assertSame(200, $response->status, json_encode($response->body) ?: '');
	}

	/** A bearer token for the account, with every scope there is. */
	protected function token(string $name): string {
		if (!isset($this->tokens[$name])) {
			$this->account($name);
			$userId = self::PREFIX . $name;
			$code = $userId . '-code-' . bin2hex(random_bytes(6));
			$token = $userId . '-token-' . bin2hex(random_bytes(12));

			$auth = Server::get(ClientAuthRequest::class);
			$auth->authorize($this->clientId(), $userId, $userId, self::SCOPES, $code);
			$auth->exchange($this->clientId(), $code, $token);
			$this->tokens[$name] = $token;
		}

		return $this->tokens[$name];
	}

	/**
	 * A bearer token for the account granted only `$scopes`, from an app of
	 * its own: a second authorization of the same app would replace the
	 * first, and with it the token `token()` handed out.
	 *
	 * @param string[] $scopes
	 */
	protected function scopedToken(string $name, array $scopes): string {
		$key = $name . ' ' . implode(' ', $scopes);
		if (!isset($this->scopedTokens[$key])) {
			$this->account($name);
			$userId = self::PREFIX . $name;
			$app = self::APP . '-' . substr(md5($key), 0, 12);

			$client = new SocialClient();
			$client->setAppName($app)
				->setAppWebsite('https://client.example')
				->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob'])
				->setAppScopes(self::SCOPES)
				->setAppClientId($app . '-id')
				->setAppClientSecret($app . '-secret');
			$requests = Server::get(ClientRequest::class);
			$requests->saveApp($client);
			$clientId = $requests->getFromClientId($app . '-id')->getId();

			$code = $userId . '-code-' . bin2hex(random_bytes(6));
			$token = $userId . '-token-' . bin2hex(random_bytes(12));
			$auth = Server::get(ClientAuthRequest::class);
			$auth->authorize($clientId, $userId, $userId, $scopes, $code);
			$auth->exchange($clientId, $code, $token);
			$this->scopedTokens[$key] = $token;
		}

		return $this->scopedTokens[$key];
	}

	private function clientId(): int {
		if ($this->clientId === 0) {
			$client = new SocialClient();
			$client->setAppName(self::APP)
				->setAppWebsite('https://client.example')
				->setAppRedirectUris(['urn:ietf:wg:oauth:2.0:oob'])
				->setAppScopes(self::SCOPES)
				->setAppClientId(self::APP . '-id')
				->setAppClientSecret(self::APP . '-secret');
			$requests = Server::get(ClientRequest::class);
			$requests->saveApp($client);
			$this->clientId = $requests->getFromClientId(self::APP . '-id')->getId();
		}

		return $this->clientId;
	}

	/**
	 * Calls one route.
	 *
	 * @param class-string $controller
	 * @param array<string, mixed> $route the route's own parameters, which are also the method's
	 * @param array<string, mixed> $query the query string (GET) or the form body (anything else)
	 * @param string|null $as the account calling, or null for no token at all
	 * @param bool $webSession call as the web app does instead: `$as` signed in
	 *                         to Nextcloud, with the session's CSRF token and no bearer token
	 * @param string|null $bearer this bearer token for `$as` rather than the one with every scope
	 * @param bool $appPassword call as a client holding a Nextcloud app password of `$as`'s
	 *                          does: Basic auth, which Nextcloud signs in, and
	 *                          `OCS-APIRequest: true` in place of a CSRF token
	 * @param array<string, string> $headers further request headers, e.g. `['Accept' => 'application/activity+json']`
	 * @param array<class-string, object> $services services the controller is built with in place of the container's
	 */
	protected function call(
		string $controller,
		string $method,
		array $route = [],
		array $query = [],
		?string $as = 'alice',
		string $verb = 'GET',
		string $path = '/apps/social/api/test',
		array $files = [],
		bool $webSession = false,
		?string $bearer = null,
		bool $appPassword = false,
		array $headers = [],
		array $services = [],
	): ApiResponse {
		$server = [
			'REQUEST_METHOD' => $verb,
			'REQUEST_URI' => '/index.php' . $path . (($query === [] || $verb !== 'GET') ? '' : '?' . http_build_query($query)),
			'SCRIPT_NAME' => '/index.php',
		];
		$session = Server::get(IUserSession::class);
		if ($webSession && $as !== null) {
			if (!isset($this->withoutAccount[$as])) {
				$this->account($as);
			}
			$session->setUser(Server::get(IUserManager::class)->get(self::PREFIX . $as));
			$server['HTTP_REQUESTTOKEN'] = Server::get(CsrfTokenManager::class)->getToken()->getEncryptedValue();
		} elseif ($appPassword && $as !== null) {
			if (!isset($this->withoutAccount[$as])) {
				$this->account($as);
			}
			$password = $this->appPassword($as);
			$server['PHP_AUTH_USER'] = self::PREFIX . $as;
			$server['PHP_AUTH_PW'] = $password;
			$server['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode(self::PREFIX . $as . ':' . $password);
			$server['HTTP_OCS_APIREQUEST'] = 'true';
			$server['REMOTE_ADDR'] = '127.0.0.1';
		} elseif ($as !== null) {
			$server['HTTP_AUTHORIZATION'] = 'Bearer ' . ($bearer ?? $this->token($as));
		}
		if ($verb !== 'GET') {
			$server['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
		}
		foreach ($headers as $name => $value) {
			$server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
		}

		$request = new Request(
			[
				'get' => ($verb === 'GET') ? $query : [],
				'post' => ($verb === 'GET') ? [] : $query,
				'server' => $server,
				'method' => $verb,
				'urlParams' => array_merge($route, ['_route' => 'social.api_contract.' . $method]),
			],
			Server::get(IRequestId::class),
			Server::get(IConfig::class),
			($webSession || $appPassword) ? Server::get(CsrfTokenManager::class) : null,
		);
		if ($appPassword && $as !== null) {
			// what Nextcloud does with Basic credentials before any app runs
			$this->assertTrue(
				$session->tryBasicAuthLogin($request, Server::get(IThrottler::class)),
				'Nextcloud did not sign the app password in'
			);
		}

		$instance = $this->controller($controller, $request, $services);

		// the method's own arguments, the way the dispatcher fills them: from
		// the route first, then the query, then the declared default
		$arguments = [];
		foreach ((new ReflectionClass($instance))->getMethod($method)->getParameters() as $parameter) {
			$name = $parameter->getName();
			if (array_key_exists($name, $route)) {
				$arguments[] = $this->cast($route[$name], $parameter);
			} elseif (array_key_exists($name, $query)) {
				$arguments[] = $this->cast($query[$name], $parameter);
			} elseif ($parameter->isDefaultValueAvailable()) {
				$arguments[] = $parameter->getDefaultValue();
			} else {
				$this->fail($controller . '::' . $method . ' needs $' . $name);
			}
		}

		// the media routes read the upload where PHP puts it
		$uploads = $_FILES;
		$_FILES = $files;
		try {
			$response = $instance->$method(...$arguments);
		} finally {
			$_FILES = $uploads;
			if ($webSession || $appPassword) {
				$session->setUser(null);
			}
		}
		$this->assertInstanceOf(Response::class, $response);

		return new ApiResponse($response);
	}

	/** A Nextcloud app password of the account's, as Login Flow v2 hands a client one. */
	private function appPassword(string $name): string {
		$password = Server::get(ISecureRandom::class)->generate(72, ISecureRandom::CHAR_ALPHANUMERIC);
		Server::get(TokenProvider::class)->generateToken(
			$password, self::PREFIX . $name, self::PREFIX . $name, null, 'apictr-app-password', IToken::PERMANENT_TOKEN
		);

		return $password;
	}

	/** A route argument as the dispatcher types it from its declaration. */
	private function cast(mixed $value, \ReflectionParameter $parameter): mixed {
		$type = $parameter->getType();
		if (!($type instanceof ReflectionNamedType) || !is_scalar($value)) {
			return $value;
		}

		return match ($type->getName()) {
			'int' => (int)$value,
			'bool' => in_array($value, [true, 1, '1', 'true'], true),
			'string' => (string)$value,
			default => $value,
		};
	}

	/**
	 * The controller, built the way a request builds it: by a fresh app
	 * container that hands out this request.
	 *
	 * Fresh, because the app's services keep what they read for the length
	 * of a request — the viewer's filters, their actor, a parent's ids — and
	 * a test makes many requests in one process. Reusing one container would
	 * answer the second request with what the first one remembered. A new
	 * container registers itself as the app's, so services the code looks up
	 * through `Server::get()` are this request's too; tearDown() puts the
	 * one the server booted with back.
	 *
	 * @param class-string $class
	 * @param array<class-string, object> $services
	 */
	private function controller(string $class, IRequest $request, array $services = []): object {
		$container = new DIContainer('social');
		// This isolated request container must carry the aliases registered by
		// Application::register(), just like the real Nextcloud app container.
		$container->registerAlias(IRecommendationSignalEvents::class, RecommendationSignalService::class);
		$container->registerAlias(IRecommendationSignalRecorder::class, RecommendationSignalService::class);
		$container->registerService(IRequest::class, static fn (): IRequest => $request);
		$this->registerFrozenClock($container);
		foreach ($services as $name => $service) {
			$container->registerService($name, static fn (): object => $service);
		}

		$controller = $container->get($class);
		$this->assertInstanceOf($class, $controller);

		return $controller;
	}

	/**
	 * Fails with every departure from the entity's schema.
	 *
	 * @param mixed $data the decoded wire payload
	 */
	protected function assertEntity(string $entity, mixed $data, string $message = ''): void {
		$errors = $this->schemas->validate($data, $entity);
		$this->assertSame(
			[], $errors,
			trim($message . "\n" . $entity . ' departs from its schema:') . "\n" . implode("\n", $errors)
		);
	}

	/**
	 * Fails with every departure of any entity of the list.
	 *
	 * @param mixed $list the decoded wire payload, a JSON array
	 */
	protected function assertEntities(string $entity, mixed $list, string $message = ''): void {
		$this->assertIsArray($list, $message . ': a list of ' . $entity . ' is a JSON array');
		$errors = [];
		foreach ($list as $index => $item) {
			foreach ($this->schemas->validate($item, $entity) as $error) {
				$errors[] = '[' . $index . ']' . substr($error, 1);
			}
		}

		$this->assertSame(
			[], $errors,
			trim($message . "\n" . $entity . ' list departs from its schema:') . "\n" . implode("\n", $errors)
		);
	}

	private function removeAccount(string $name): void {
		$userId = self::PREFIX . $name;
		try {
			$actor = Server::get(ActorsRequest::class)->getFromUsername($userId);
			AP::instance()->getInterfaceFromType(Person::TYPE)->delete($actor);
			Server::get(CacheActorsRequest::class)->deleteCacheById($actor->getId());
		} catch (ActorDoesNotExistException $e) {
		}

		Server::get(ActorsRequest::class)->delete($userId);
		Server::get(ClientAuthRequest::class)->deleteRelatedId($userId);

		$user = Server::get(IUserManager::class)->get($userId);
		$user?->delete();
	}

	private function removeClient(): void {
		Server::get(ClientRequest::class)->deleteApp(self::APP . '-id');
		foreach (array_keys($this->scopedTokens) as $key) {
			Server::get(ClientRequest::class)->deleteApp(self::APP . '-' . substr(md5($key), 0, 12) . '-id');
		}
		$this->clientId = 0;
	}
}
