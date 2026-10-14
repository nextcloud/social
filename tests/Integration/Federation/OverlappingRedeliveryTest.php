<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Federation;

use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\HostBreakerRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Object\AnnounceInterface;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\Server;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A follow lookup that misses as many times as it is told to, then answers
 * from the table: how the table looks to a delivery that looked before an
 * overlapping one wrote.
 */
class FollowsRequestMissingLookups extends FollowsRequest {
	public int $misses = 0;

	#[\Override]
	public function getByPersons(string $actorId, string $remoteActorId): Follow {
		if ($this->misses > 0) {
			$this->misses--;

			throw new FollowNotFoundException('not looked up yet');
		}

		return parent::getByPersons($actorId, $remoteActorId);
	}
}

/** The same for an action: a like's lookup. */
class ActionsRequestMissingLookups extends ActionsRequest {
	public int $misses = 0;

	#[\Override]
	public function getActionFromItem(ACore $item): ACore {
		if ($this->misses > 0) {
			$this->misses--;

			throw new ActionDoesNotExistException('not looked up yet');
		}

		return parent::getActionFromItem($item);
	}
}

/** The same for a stream: a boost's lookup by booster, and a post's by id. */
class StreamRequestMissingLookups extends StreamRequest {
	public int $announceMisses = 0;
	public int $idMisses = 0;
	public string $missedId = '';

	#[\Override]
	public function getAnnounceBy(string $objectId, string $actorId): Stream {
		if ($this->announceMisses > 0) {
			$this->announceMisses--;

			throw new StreamNotFoundException('not looked up yet');
		}

		return parent::getAnnounceBy($objectId, $actorId);
	}

	#[\Override]
	public function getStreamById(string $id, bool $asViewer = false, int $format = ACore::FORMAT_ACTIVITYPUB): Stream {
		if ($this->idMisses > 0 && $id === $this->missedId) {
			$this->idMisses--;

			throw new StreamNotFoundException('not looked up yet');
		}

		return parent::getStreamById($id, $asViewer, $format);
	}
}

/**
 * A peer that times out delivers the same activity again while the first
 * copy is still being handled — a Follow's first copy fetches the follower
 * and delivers the Accept between its lookup and its write. Both copies
 * then pass the lookup that keeps a delivery from being stored twice, and
 * the second write meets the row the first one left.
 *
 * The overlap is replayed against the real database by delivering twice
 * through the real handlers, with the second delivery's lookup missing the
 * way it did for the first: the table is then exactly what the overlapping
 * copy saw. The second copy must leave one row, count once, raise nothing
 * (an exception here is the 500 that makes the peer deliver a third time)
 * and leave the connection usable — on PostgreSQL a failed insert aborts
 * the transaction it runs in.
 */
class OverlappingRedeliveryTest extends InboxTestCase {
	private const CAROL = 'https://mastodon.example/users/carol';
	private const POST = self::CAROL . '/statuses/116000000000000002';

	private CacheActorsRequest $actors;
	/** the registry as the container built it, put back after the test */
	private AP $registry;

	protected function hosts(): array {
		return ['mastodon.example'];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->actors = Server::get(CacheActorsRequest::class);
		// a copy of the registry carries this test's handlers; the container
		// keeps the one it built, which is what the next test gets back
		$this->registry = AP::instance();
		AP::set(clone $this->registry);
		$this->localPost();
		$this->remoteActor(self::CAROL, 'carol');
		// counted, so the counters move: a never-counted account skips a bump
		$this->actors->setCounts($this->alice(), ['followers' => 0, 'following' => 0, 'post' => 0]);
	}

	protected function tearDown(): void {
		AP::set($this->registry);
		Server::get(HostBreakerRequest::class)->close('mastodon.example');
		parent::tearDown();
	}

	/**
	 * A handler built the way the container builds it, with some of its
	 * collaborators replaced.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @param array<class-string, object> $with by the parameter's declared class
	 * @return T
	 */
	private function handler(string $class, array $with): object {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			$name = ($type instanceof ReflectionNamedType && !$type->isBuiltin()) ? $type->getName() : '';
			$arguments[] = $with[$name] ?? Server::get($name);
		}

		return new $class(...$arguments);
	}

	/**
	 * A collaborator built the way the container builds it, as a subclass.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function collaborator(string $class): object {
		return $this->handler($class, []);
	}

	private function activity(string $type, string $id, array|string $object, array $more = []): string {
		return (string)json_encode([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $id,
			'type' => $type,
			'actor' => self::CAROL,
			'object' => $object,
		] + $more);
	}

	private function followers(): int {
		return (int)($this->actors->getCounts($this->alice())['followers'] ?? -1);
	}

	/** The connection is still good for a read after whatever happened before. */
	private function assertConnectionUsable(): void {
		$this->assertNotNull($this->stored($this->localPostId()), 'the connection still answers');
	}

	/** Rows of a table under this test's remote host. */
	private function remoteRows(string $table, string $column): array {
		return $this->rows($table, $column, 'https://mastodon.example/');
	}

	public function testAFollowDeliveredAgainWhileTheFirstCopyIsStillBeingHandledIsOneCountedFollowAnsweredTwice(): void {
		$follows = $this->collaborator(FollowsRequestMissingLookups::class);
		AP::instance()->followInterface = $this->handler(FollowInterface::class, [FollowsRequest::class => $follows]);
		$follow = $this->activity('Follow', self::CAROL . '#follows/1', $this->alice());

		$this->assertTrue($this->deliver($follow));
		$this->assertCount(1, $this->remoteRows(CoreRequestBuilder::TABLE_FOLLOWS, 'actor_id'));
		$this->assertSame(1, $this->followers());
		$accepts = count($this->rows(CoreRequestBuilder::TABLE_REQUEST_QUEUE, 'author', $this->alice()));

		$follows->misses = 1;
		$this->assertTrue($this->deliver($follow));

		$rows = $this->remoteRows(CoreRequestBuilder::TABLE_FOLLOWS, 'actor_id');
		$this->assertCount(1, $rows, 'one follow row');
		$this->assertSame(1, (int)$rows[0]['accepted'], 'accepted');
		$this->assertSame(1, $this->followers(), 'counted once');
		$this->assertCount(1, $this->remoteRows(CoreRequestBuilder::TABLE_STREAM, 'id'), 'one follow notification');
		$this->assertGreaterThan(
			$accepts, count($this->rows(CoreRequestBuilder::TABLE_REQUEST_QUEUE, 'author', $this->alice())),
			'the Accept is sent again'
		);
		$this->assertConnectionUsable();
	}

	public function testALikeDeliveredAgainWhileTheFirstCopyIsStillBeingHandledIsOneLikeCountedOnce(): void {
		$actions = $this->collaborator(ActionsRequestMissingLookups::class);
		AP::instance()->likeInterface = $this->handler(LikeInterface::class, [ActionsRequest::class => $actions]);
		$like = $this->activity('Like', self::CAROL . '#likes/1', $this->localPostId());

		$this->assertTrue($this->deliver($like));
		$this->assertSame(1, $this->statusEntity($this->localPostId())['favourites_count']);

		$actions->misses = 1;
		$this->assertTrue($this->deliver($like));

		$this->assertCount(1, $this->remoteRows(CoreRequestBuilder::TABLE_ACTIONS, 'actor_id'), 'one like row');
		$this->assertSame(1, $this->statusEntity($this->localPostId())['favourites_count'], 'counted once');
		$this->assertCount(1, $this->rows(CoreRequestBuilder::TABLE_STREAM, 'id', $this->localPostId() . '/notification'), 'one like notification');
		$this->assertConnectionUsable();
	}

	public function testABoostDeliveredAgainWhileTheFirstCopyIsStillBeingHandledIsOneBoostCountedOnce(): void {
		$streams = $this->collaborator(StreamRequestMissingLookups::class);
		$actions = $this->collaborator(ActionsRequestMissingLookups::class);
		AP::instance()->announceInterface = $this->handler(
			AnnounceInterface::class, [StreamRequest::class => $streams, ActionsRequest::class => $actions]
		);
		$announce = $this->activity('Announce', self::CAROL . '/statuses/116000000000000003/activity', $this->localPostId(), [
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [$this->alice(), self::CAROL . '/followers'],
		]);

		$this->assertTrue($this->deliver($announce));
		$this->assertSame(1, $this->statusEntity($this->localPostId())['reblogs_count']);

		// a boost is two rows, and the second copy looked both up in vain
		$streams->announceMisses = 1;
		$actions->misses = 1;
		$this->assertTrue($this->deliver($announce));

		$this->assertCount(1, $this->remoteRows(CoreRequestBuilder::TABLE_ACTIONS, 'actor_id'), 'one boost action');
		$this->assertCount(1, $this->remoteRows(CoreRequestBuilder::TABLE_STREAM, 'id'), 'one Announce');
		$this->assertCount(1, $this->rows(CoreRequestBuilder::TABLE_STREAM, 'id', $this->localPostId() . '/notification'), 'one boost notification');
		$this->assertSame(1, $this->statusEntity($this->localPostId())['reblogs_count'], 'counted once');
		$this->assertConnectionUsable();
	}

	public function testAPostDeliveredAgainWhileTheFirstCopyIsStillBeingHandledIsOnePostNotifiedOnce(): void {
		$streams = $this->collaborator(StreamRequestMissingLookups::class);
		AP::instance()->noteInterface = $this->handler(NoteInterface::class, [StreamRequest::class => $streams]);
		$create = $this->activity('Create', self::POST . '/activity', [
			'id' => self::POST,
			'type' => 'Note',
			'attributedTo' => self::CAROL,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [self::CAROL . '/followers', $this->alice()],
			'content' => '<p><a href="' . $this->alice() . '">@inboxitest</a> twice</p>',
			'tag' => [['type' => 'Mention', 'href' => $this->alice(), 'name' => '@inboxitest@' . parse_url($this->alice(), PHP_URL_HOST)]],
			'published' => '2026-05-01T10:00:00Z',
		], ['to' => ['https://www.w3.org/ns/activitystreams#Public']]);

		$this->assertTrue($this->deliver($create));
		$this->assertNotNull($this->stored(self::POST));
		$once = $this->remoteRows(CoreRequestBuilder::TABLE_STREAM, 'id');
		$this->assertCount(2, $once, 'the post and its mention notification');

		// the second copy looks the post up before storing it and again on
		// its way to storing it, as the first did
		$streams->missedId = self::POST;
		$streams->idMisses = 2;
		$this->assertTrue($this->deliver($create));

		$this->assertSame(0, $streams->idMisses, 'both lookups of the second copy missed');
		$this->assertSame($once, $this->remoteRows(CoreRequestBuilder::TABLE_STREAM, 'id'), 'the second wrote nothing more');
		$this->assertConnectionUsable();
	}
}
