<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\RequestQueueRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;
use OCA\Social\Service\RequestQueueService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The half of inbox forwarding that only a real database can answer: that the
 * followers of a local post come back from the join with their cached actor
 * attached (the shared inbox lives on that actor, so an unjoined row would
 * forward to nobody), and that a document handed to the queue as bytes is
 * still those bytes when it comes back out to be delivered.
 *
 * The decision of *whether* to forward is unit-tested; this is the plumbing
 * underneath it.
 */
class InboxForwardingTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/fwdtest/users/alice';
	private const ONE = 'https://a.example/fwdtest/users/one';
	private const TWO = 'https://b.example/fwdtest/users/two';
	private const PARENT = 'https://cloud.example.org/fwdtest/notes/parent';

	/** what arrived: a signature the recipients will check, so it must survive intact */
	private const SOURCE = '{"type":"Create","id":"https://remote.example/a/1","signature":{"type":"RsaSignature2017","signatureValue":"abc+/="}}';

	private FollowsRequest $followsRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private StreamRequest $streamRequest;
	private RequestQueueService $requestQueueService;
	private RequestQueueRequest $requestQueueRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->requestQueueService = Server::get(RequestQueueService::class);
		$this->requestQueueRequest = Server::get(RequestQueueRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ALICE, self::ONE, self::TWO] as $id) {
			$this->followsRequest->deleteRelatedId($id);
			$this->cacheActorsRequest->deleteCacheById($id);
		}
		$this->streamRequest->deleteById(self::PARENT, Note::TYPE);
	}

	private function follower(string $id, string $sharedInbox): void {
		$actor = new Person();
		$actor->setId($id);
		$actor->setInbox($id . '/inbox');
		$actor->setSharedInbox($sharedInbox);
		$actor->setAccount(basename($id) . '@' . parse_url($id, PHP_URL_HOST));
		$this->cacheActorsRequest->save($actor);

		$follow = new Follow();
		$follow->setId($id . '#follow/alice');
		$follow->setActorId($id);
		$follow->setObjectId(self::ALICE);
		$follow->setFollowId(self::ALICE . '/followers');
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);
	}

	public function testTheFollowersOfALocalPostComeBackWithSomewhereToDeliverTo(): void {
		$this->follower(self::ONE, 'https://a.example/inbox');
		$this->follower(self::TWO, 'https://b.example/inbox');

		$inboxes = [];
		foreach ($this->followsRequest->getFollowersByActorId(self::ALICE) as $follow) {
			$actor = $follow->getActor();
			$this->assertNotNull($actor, 'a follower without its cached actor can never be delivered to');
			$inboxes[] = $actor->getSharedInbox();
		}

		sort($inboxes);
		$this->assertSame(['https://a.example/inbox', 'https://b.example/inbox'], $inboxes);
	}

	public function testAForwardedDocumentIsQueuedByteForByte(): void {
		$token = $this->requestQueueService->generateRequestQueueFromSource(
			[
				new InstancePath('https://a.example/inbox', InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_LOW),
				new InstancePath('https://b.example/inbox', InstancePath::TYPE_GLOBAL, InstancePath::PRIORITY_LOW),
			],
			self::SOURCE,
			self::ALICE
		);

		$queued = $this->requestQueueRequest->getFromToken($token);

		try {
			$this->assertCount(2, $queued);
			foreach ($queued as $request) {
				// re-encoding here would invalidate the sender's signature
				$this->assertSame(self::SOURCE, $request->getActivity());
				// signed on the wire as the local author whose thread this is
				$this->assertSame(self::ALICE, $request->getAuthor());
				$this->assertSame(RequestQueue::STATUS_STANDBY, $request->getStatus());
			}
		} finally {
			foreach ($queued as $request) {
				$this->requestQueueRequest->delete($request);
			}
		}
	}

	public function testALocalPostIsRecognisedAsOursWhenItComesBackOut(): void {
		// reading a stream joins its author out of the actor cache; without the
		// row the post is invisible however well it was stored
		$alice = new Person();
		$alice->setId(self::ALICE);
		$alice->setInbox(self::ALICE . '/inbox');
		$alice->setAccount('alice@cloud.example.org');
		$alice->setLocal(true);
		$this->cacheActorsRequest->save($alice);

		$parent = new Note();
		$parent->setId(self::PARENT);
		$parent->setAttributedTo(self::ALICE);
		$parent->setVisibility(Stream::TYPE_PUBLIC);
		$parent->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$parent->setLocal(true);
		$this->streamRequest->save($parent);

		$stored = $this->streamRequest->getStreamById(self::PARENT);

		// the forwarding decision turns on all three surviving the round trip
		$this->assertTrue($stored->isLocal());
		$this->assertSame(self::ALICE, $stored->getAttributedTo());
		$this->assertSame(Stream::TYPE_PUBLIC, $stored->getVisibility());
	}
}
