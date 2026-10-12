<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Api;

use OC\AppFramework\Http\Request;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Drives the client API's controllers the way a signed-in reader's request
 * does, against the real database.
 *
 * One Nextcloud user with a real Social account per test class — created the
 * way `occ social:account:create` creates one, key pair, cached copy and
 * loopback follow included — signed in for every test. A controller is built
 * by the container's own services around a request carrying the
 * `OCS-APIRequest` header, which is what the web app sends and what satisfies
 * the CSRF check `currentSession()` makes.
 *
 * Remote accounts and posts are seeded through the production Request
 * classes; everything a test seeds lives under its own URL prefix and is
 * removed again in `tearDown()`.
 */
abstract class ReaderTestCase extends TestCase {
	protected const REMOTE = 'https://remote.example/apitest';

	private static string $uid = '';

	/** @var string[] stream ids to remove after the test */
	private array $streams = [];

	/** @var string[] remote actor ids to remove after the test */
	private array $actors = [];

	private int $sequence = 0;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// a deleted handle stays reserved for the retention period, so every
		// class registers a fresh one
		self::$uid = 'itestapi' . bin2hex(random_bytes(4));
		Server::get(IUserManager::class)->createUser(self::$uid, 'Itest-' . bin2hex(random_bytes(16)));
		Server::get(AccountService::class)->createActor(self::$uid, self::$uid);
	}

	public static function tearDownAfterClass(): void {
		if (self::$uid !== '') {
			$actorsRequest = Server::get(ActorsRequest::class);
			$actor = $actorsRequest->getFromUsername(self::$uid);
			Server::get(FollowsRequest::class)->deleteRelatedId($actor->getId());
			Server::get(CacheActorsRequest::class)->deleteCacheById($actor->getId());
			Server::get(IUserSession::class)->setUser(null);
			Server::get(IUserManager::class)->get(self::$uid)?->delete();
			$actorsRequest->delete(self::$uid);
			self::$uid = '';
		}

		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		Server::get(IUserSession::class)->setUser($this->user());
	}

	protected function tearDown(): void {
		$streamRequest = Server::get(StreamRequest::class);
		foreach ($this->streams as $id) {
			$streamRequest->deleteById($id);
		}
		$followsRequest = Server::get(FollowsRequest::class);
		$cacheActorsRequest = Server::get(CacheActorsRequest::class);
		foreach ($this->actors as $id) {
			$followsRequest->deleteRelatedId($id);
			$cacheActorsRequest->deleteCacheById($id);
		}
		$this->streams = [];
		$this->actors = [];

		parent::tearDown();
	}

	protected function user(): IUser {
		$user = Server::get(IUserManager::class)->get(self::$uid);
		$this->assertNotNull($user);

		return $user;
	}

	/** The signed-in reader's cached actor, as the controllers load it. */
	protected function viewer(): Person {
		return Server::get(CacheActorService::class)->getFromLocalAccount(self::$uid);
	}

	/**
	 * A controller of the app as the container would build it for a request
	 * with these query parameters and headers.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @param array<string, mixed> $query
	 * @param array<string, string> $headers e.g. `['Range' => 'bytes=0-9']`
	 *
	 * @return T
	 */
	protected function controller(string $class, array $query = [], array $headers = []): object {
		$server = ['REQUEST_METHOD' => 'GET', 'HTTP_OCS_APIREQUEST' => 'true'];
		foreach ($headers as $name => $value) {
			$server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
		}
		$request = new Request(
			['get' => $query, 'server' => $server, 'method' => 'GET'],
			Server::get(IRequestId::class),
			Server::get(IConfig::class),
			Server::get(CsrfTokenManager::class),
		);

		$arguments = [];
		$constructor = (new ReflectionClass($class))->getConstructor();
		foreach ($constructor?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type, $class . '::$' . $parameter->getName());
			$arguments[] = $type->getName() === IRequest::class ? $request : Server::get($type->getName());
		}

		return new $class(...$arguments);
	}

	/**
	 * The body the response puts on the wire, rendered inside whatever block
	 * calls this: serialising a status is where its account, its media and
	 * the viewer's flags on it are read, so a count that stopped before the
	 * render would miss them.
	 */
	protected static function render(Response $response): string {
		if ($response instanceof JSONResponse) {
			return $response->render();
		}
		if ($response instanceof DataResponse) {
			return (string)json_encode($response->getData());
		}

		return '';
	}

	/** @return array<int|string, mixed> */
	protected static function decode(Response $response): array {
		$decoded = json_decode(self::render($response), true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * A remote account, cached as if it had been fetched: a year old, so that
	 * a new account's calm notification policy does not hold what it sends.
	 */
	protected function remoteActor(string $name): Person {
		$id = static::REMOTE . '/users/' . $name;
		$person = new Person();
		$person->setId($id)->setPreferredUsername($name);
		$person->setAccount($name . '@remote.example')
			->setName('Remote ' . $name)
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal(false);
		$person->setCreation(time() - 365 * 86400);
		Server::get(CacheActorsRequest::class)->save($person);
		$this->actors[] = $id;

		return Server::get(CacheActorsRequest::class)->getFromId($id);
	}

	/** The signed-in reader follows `$author`, accepted. */
	protected function follow(Person $author): void {
		$viewer = $this->viewer();
		$follow = new Follow();
		$follow->setId(static::REMOTE . '/follows/' . md5($viewer->getId() . $author->getId()));
		$follow->setActorId($viewer->getId());
		$follow->setObjectId($author->getId());
		$follow->setFollowId($author->getFollowers());
		$follow->setAccepted(true);
		Server::get(FollowsRequest::class)->save($follow);
	}

	/**
	 * A public post by `$author`, a second newer than the one before it, so
	 * that the order of a page is the order of seeding. The clock starts now
	 * and runs ahead of it, which keeps whatever another test left on the
	 * shared instance below the seeded page.
	 *
	 * @param callable(Note): void|null $shape adds attachments, a reply, …
	 */
	protected function post(Person $author, string $content, ?callable $shape = null): Note {
		$this->sequence++;
		$published = time() + $this->sequence;

		$note = new Note();
		$note->setId(static::REMOTE . '/notes/' . static::class . '/' . $this->sequence . '-' . bin2hex(random_bytes(3)));
		$note->setAttributedTo($author->getId());
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$author->getFollowers()]);
		$note->setContent('<p>' . htmlspecialchars($content) . '</p>');
		$note->setPublishedTime($published);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', $published));
		if ($shape !== null) {
			$shape($note);
		}
		Server::get(StreamRequest::class)->save($note);
		$this->streams[] = $note->getId();

		return $note;
	}

	/**
	 * `$booster`'s public boost of `$post`, a second newer than the post
	 * before it, stored as an inbound one is.
	 */
	protected function boost(Person $booster, Note $post): Announce {
		$this->sequence++;
		$published = time() + $this->sequence;

		$boost = new Announce();
		$boost->setId(static::REMOTE . '/boosts/' . static::class . '/' . $this->sequence . '-' . bin2hex(random_bytes(3)));
		$boost->setAttributedTo($booster->getId());
		$boost->setTo(ACore::CONTEXT_PUBLIC);
		$boost->setCcArray([$booster->getFollowers()]);
		$boost->setObjectId($post->getId());
		$boost->setPublishedTime($published);
		$boost->setPublished(gmdate('Y-m-d\TH:i:s\Z', $published));
		Server::get(StreamRequest::class)->save($boost);
		$this->streams[] = $boost->getId();

		return $boost;
	}

	/**
	 * A public post by `$author` that mentions the signed-in reader, stored
	 * the way an inbound one is — through the Note interface — so that the
	 * mention notification is written beside it.
	 */
	protected function mention(Person $author): Note {
		$viewer = $this->viewer();
		$this->sequence++;

		$note = new Note();
		$note->setId(static::REMOTE . '/notes/' . static::class . '/mention-' . $this->sequence . '-' . bin2hex(random_bytes(3)));
		$note->setAttributedTo($author->getId());
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$author->getFollowers(), $viewer->getId()]);
		$note->addTag(['type' => 'Mention', 'href' => $viewer->getId(), 'name' => '@' . $viewer->getPreferredUsername()]);
		$note->setContent('<p>@' . $viewer->getPreferredUsername() . ' hello</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		AP::instance()->getInterfaceForItem($note)->save($note);

		$this->streams[] = $note->getId();
		$this->streams[] = $note->getId() . '/notification+mention/' . md5($viewer->getId());

		return $note;
	}
}
