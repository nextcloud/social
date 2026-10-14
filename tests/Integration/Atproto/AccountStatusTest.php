<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Reader\AccountStatus;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Db\AtprotoWatchRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A Bluesky account switched off, back, and gone, against the real tables:
 * hiding takes the recipient rows the viewer-limited read joins on and
 * leaves the post; restoring writes them back; a deletion takes the post,
 * the cached actor and the watch.
 */
class AccountStatusTest extends TestCase {
	private const DID = 'did:plc:accountstatusauthor000';
	private const RKEY = '3kaccountstatus';

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private AtprotoWatchRequest $watches;
	private AccountStatus $status;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->watches = Server::get(AtprotoWatchRequest::class);
		$this->status = Server::get(AccountStatus::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->streamRequest->resetViewer();
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->streamRequest->deleteById(BlueskyIds::postId(self::DID, self::RKEY), Note::TYPE);
		$this->cacheActorsRequest->deleteCacheById(BlueskyIds::actorId(self::DID));
		$this->watches->remove(self::DID);
	}

	private function seed(): string {
		$author = new Person();
		$author->setId(BlueskyIds::actorId(self::DID))->setPreferredUsername('status.bsky.social');
		$author->setAccount('status.bsky.social')
			->setFollowers(BlueskyIds::followersId(self::DID))
			->setFollowing($author->getId() . '/following')
			->setInbox($author->getId() . '/inbox')
			->setOutbox($author->getId() . '/outbox')
			->setLocal(false);
		$author->setDetailArray(ActorMapper::DETAIL, ['did' => self::DID, 'handle' => 'status.bsky.social', 'labels' => [], 'limited' => false]);
		$this->cacheActorsRequest->save($author);
		$this->cacheActorsRequest->updateDetails($author);
		$this->watches->add(self::DID, 'status.bsky.social');

		$note = new Note();
		$note->setId(BlueskyIds::postId(self::DID, self::RKEY));
		$note->setAttributedTo($author->getId());
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->addCc(BlueskyIds::followersId(self::DID));
		$note->setContent('<p>still here</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note->getId();
	}

	private function readable(string $id): bool {
		$this->streamRequest->resetViewer();
		try {
			$this->streamRequest->getStreamById($id, true);

			return true;
		} catch (StreamNotFoundException) {
			return false;
		}
	}

	public function testSwitchedOffHidesAndKeepsAndActiveAgainRestores(): void {
		$id = $this->seed();
		$this->assertTrue($this->readable($id));

		$this->status->apply(self::DID, false, AccountStatus::DEACTIVATED);

		$this->assertFalse($this->readable($id), 'hidden from a public read');
		$this->assertSame($id, $this->streamRequest->getStreamById($id)->getId(), 'the row is kept');
		$actor = $this->cacheActorsRequest->getFromId(BlueskyIds::actorId(self::DID));
		$this->assertTrue(BlueskyActorService::isLimited($actor));
		$this->assertSame(AccountStatus::DEACTIVATED, BlueskyActorService::status($actor));

		$this->status->apply(self::DID, true, '');

		$this->assertTrue($this->readable($id), 'readable again');
		$actor = $this->cacheActorsRequest->getFromId(BlueskyIds::actorId(self::DID));
		$this->assertFalse(BlueskyActorService::isLimited($actor));
		$this->assertSame('', BlueskyActorService::status($actor));
		$this->assertLessThanOrEqual(time(), $this->watches->getByDid(self::DID)->nextSync, 'read on the next pass');
	}

	public function testDeletedPurgesThePostsTheActorAndTheWatch(): void {
		$id = $this->seed();

		$this->status->apply(self::DID, false, AccountStatus::DELETED);

		try {
			$this->streamRequest->getStreamById($id);
			$this->fail('the post stayed');
		} catch (StreamNotFoundException) {
		}
		try {
			$this->cacheActorsRequest->getFromId(BlueskyIds::actorId(self::DID));
			$this->fail('the actor stayed');
		} catch (CacheActorDoesNotExistException) {
		}
		$this->assertNull($this->watches->getByDid(self::DID));
	}
}
