<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Federation;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\SignatureService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Delivers activities to the inbox the way ActivityPubController does once a
 * request's HTTP signature has been checked: ImportService::importFromJson(),
 * the origin stamped as the signature's host, ImportService::parseIncomingRequest()
 * and the real interfaces behind it, against the real database. An activity of
 * a type nothing handles is acknowledged, as the controller acknowledges it.
 *
 * Every test runs with a local account of its own (`LOCAL`), one public post
 * of it (`localPost()`), and the remote actors it names seeded into the actor
 * cache, so nothing reaches for the network. Everything written under the
 * hosts a test declares is removed before and after it.
 */
abstract class InboxTestCase extends TestCase {
	protected const LOCAL = 'inboxitest';

	protected ImportService $importService;
	protected StreamRequest $streamRequest;
	protected CacheActorsRequest $cacheActorsRequest;
	protected IDBConnection $connection;
	protected string $social = '';

	/** Fresh for each test: a nid cached for an id outlives the row it was read from. */
	private string $localPostToken = '';

	/** @return list<string> the remote hosts whose rows this test owns */
	abstract protected function hosts(): array;

	protected function setUp(): void {
		parent::setUp();
		$this->importService = Server::get(ImportService::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->connection = Server::get(IDBConnection::class);
		$this->social = Server::get(ConfigService::class)->getSocialUrl();
		$this->localPostToken = '1700000000' . random_int(1000000000, 9999999999);
		$this->cleanup();
		$this->createLocalAccount();
	}

	protected function tearDown(): void {
		$this->cleanup();
		Server::get(ActorsRequest::class)->delete(self::LOCAL);
		parent::tearDown();
	}

	protected function alice(): string {
		return $this->social . '@' . self::LOCAL;
	}

	protected function localPostId(): string {
		return $this->alice() . '/' . $this->localPostToken;
	}

	private function createLocalAccount(): void {
		$actorsRequest = Server::get(ActorsRequest::class);
		try {
			$actorsRequest->getFromUsername(self::LOCAL);
		} catch (ActorDoesNotExistException $e) {
			$actor = new Person();
			$actor->setPreferredUsername(self::LOCAL);
			$actor->setUserId(self::LOCAL);
			$actor->setName('Inbox Test');
			Server::get(SignatureService::class)->generateKeys($actor);
			$actorsRequest->create($actor);
		}

		$local = $actorsRequest->getFromUsername(self::LOCAL);
		$local->setLocal(true);
		$local->setSource((string)json_encode($local, JSON_UNESCAPED_SLASHES));
		$this->cacheActorsRequest->save($local);
	}

	/** A public post of the local account, as PostService stores one. */
	protected function localPost(): Note {
		$note = new Note();
		$note->setId($this->localPostId());
		$note->setAttributedTo($this->alice());
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$this->alice() . '/followers']);
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$note->setContent('<p>Tomatoes are up!</p>');
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$note->setPublishedTime(time());
		$note->setLocal(true);
		$this->streamRequest->save($note);

		return $this->streamRequest->getStreamById($this->localPostId());
	}

	/** A remote actor, cached the way a fetched one is. */
	protected function remoteActor(string $id, string $username, array $extra = []): Person {
		$host = (string)parse_url($id, PHP_URL_HOST);
		$actor = new Person();
		$actor->setId($id);
		$actor->setPreferredUsername($username);
		$actor->setName(ucfirst($username));
		$actor->setAccount($username . '@' . $host);
		$actor->setInbox($id . '/inbox');
		$actor->setSharedInbox('https://' . $host . '/inbox');
		$actor->setFollowers($id . '/followers');
		$actor->setFollowing($id . '/following');
		$actor->setOutbox($id . '/outbox');
		$actor->setUrl($id);
		// fetched a while ago: an Update signed now is newer than the copy
		$actor->setCreation(time() - 3600);
		$actor->setAlsoKnownAs($extra['alsoKnownAs'] ?? []);
		$actor->setSource((string)json_encode(['id' => $id, 'type' => 'Person', 'alsoKnownAs' => $extra['alsoKnownAs'] ?? []]));
		$this->cacheActorsRequest->save($actor);

		return $actor;
	}

	/**
	 * A fixture from tests/Federation/fixtures with this instance's local
	 * account and post filled in.
	 */
	protected function fixture(string $name): string {
		return str_replace(
			['{{LOCAL_POST}}', '{{ALICE}}'],
			[$this->localPostId(), $this->alice()],
			(string)file_get_contents(__DIR__ . '/../../Federation/fixtures/' . $name . '.json')
		);
	}

	/**
	 * The inbox, from the parsed body on: what ActivityPubController::sharedInbox()
	 * runs once the request's signature named `$origin`.
	 *
	 * @return bool false when no interface handles the type, which the
	 *              controller acknowledges with a 202 and logs
	 */
	protected function deliver(string $json, string $origin = ''): bool {
		$activity = $this->importService->importFromJson($json);
		if ($origin === '') {
			$origin = (string)parse_url($activity->getActorId() ?: $activity->getId(), PHP_URL_HOST);
		}
		$activity->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());

		try {
			$this->importService->parseIncomingRequest($activity);
		} catch (ItemUnknownException $e) {
			return false;
		}

		return true;
	}

	protected function stored(string $id, int $format = ACore::FORMAT_ACTIVITYPUB): ?Stream {
		try {
			return $this->streamRequest->getStreamById($id, false, $format);
		} catch (StreamNotFoundException $e) {
			return null;
		}
	}

	/** The Mastodon Status entity a client is served for a stored post. */
	protected function statusEntity(string $id): array {
		$stream = $this->stored($id, ACore::FORMAT_LOCAL);
		$this->assertNotNull($stream, $id . ' is stored');

		return (array)json_decode((string)json_encode($stream), true);
	}

	/** Rows of a table whose column starts with a prefix. */
	protected function rows(string $table, string $column, string $prefix): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('*')->from($table)->where(
			$qb->expr()->like($column, $qb->createNamedParameter($this->connection->escapeLikeParameter($prefix) . '%'))
		);
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	private function cleanup(): void {
		$prefixes = array_map(static fn (string $host): string => 'https://' . $host . '/', $this->hosts());
		$prefixes[] = $this->social . '@' . self::LOCAL;

		foreach ($prefixes as $prefix) {
			foreach ($this->rows(CoreRequestBuilder::TABLE_STREAM, 'id', $prefix) as $row) {
				$this->streamRequest->deleteById((string)$row['id']);
				$this->deleteWhere(CoreRequestBuilder::TABLE_STATUS_REVISIONS, 'stream_id_prim', md5((string)$row['id']), false);
			}
			foreach ([
				CoreRequestBuilder::TABLE_STREAM => ['id', 'attributed_to', 'object_id'],
				CoreRequestBuilder::TABLE_CACHE_ACTORS => ['id'],
				CoreRequestBuilder::TABLE_ACTIONS => ['actor_id', 'object_id'],
				CoreRequestBuilder::TABLE_REACTIONS => ['actor_id', 'object_id'],
				CoreRequestBuilder::TABLE_FOLLOWS => ['actor_id', 'object_id'],
				CoreRequestBuilder::TABLE_ACTOR_RELATION => ['object_id'],
				CoreRequestBuilder::TABLE_REPORTS => ['actor_id', 'account_id'],
				CoreRequestBuilder::TABLE_QUOTE_GRANTS => ['actor_id', 'target_id'],
				CoreRequestBuilder::TABLE_REQUEST_QUEUE => ['author'],
				'social_stream_queue' => ['stream_id'],
			] as $table => $columns) {
				foreach ($columns as $column) {
					$this->deleteWhere($table, $column, $prefix, true);
				}
			}
		}
	}

	private function deleteWhere(string $table, string $column, string $value, bool $prefix): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete($table)->where($prefix
			? $qb->expr()->like($column, $qb->createNamedParameter($this->connection->escapeLikeParameter($value) . '%'))
			: $qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}
}
