<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A Bluesky post whose author asked apps not to show them to logged-out
 * people, stored through the one import path a Bluesky post takes. The
 * visibility layer every read goes through (`limitToViewer()`) is what
 * keeps it from an anonymous reader and from a stranger, and hands it to a
 * follower — so that layer is what is asserted against, with the real
 * recipient rows the import wrote.
 */
class LoggedInOnlyPostTest extends TestCase {
	private const DID = 'did:plc:loggedinonlyauthor0000';
	private const RKEY = '3kloggedinonly';
	private const BASE = 'https://cloud.example.org/loggedinonly';
	private const FOLLOWER = self::BASE . '/users/follower';
	private const STRANGER = self::BASE . '/users/stranger';

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->streamRequest->resetViewer();
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->streamRequest->deleteById(BlueskyIds::postId(self::DID, self::RKEY), Note::TYPE);
		$this->followsRequest->deleteRelatedId(BlueskyIds::actorId(self::DID));
		foreach ([self::FOLLOWER, self::STRANGER, BlueskyIds::actorId(self::DID)] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
		}
	}

	private function person(string $id, string $username): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . '@cloud.example.org')
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal(true);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	private function follows(Person $follower): void {
		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/' . $follower->getPreferredUsername());
		$follow->setActorId($follower->getId());
		$follow->setObjectId(BlueskyIds::actorId(self::DID));
		$follow->setFollowId(BlueskyIds::followersId(self::DID));
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);
	}

	/** The post as the AppView shows it, with the self-label on the profile. */
	private function postView(): array {
		return [
			'uri' => BlueskyIds::atUri(self::DID, BlueskyIds::POST, self::RKEY),
			'cid' => 'bafyreiloggedinonly',
			'author' => [
				'did' => self::DID, 'handle' => 'quiet.bsky.social', 'displayName' => 'Quiet',
				'labels' => [['src' => self::DID, 'uri' => 'at://' . self::DID . '/app.bsky.actor.profile/self', 'val' => PostMapper::NO_UNAUTHENTICATED]],
			],
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'for the people who follow me', 'createdAt' => '2026-10-13T10:00:00.000Z'],
			'replyCount' => 0, 'repostCount' => 0, 'likeCount' => 0, 'quoteCount' => 0,
			'indexedAt' => '2026-10-13T10:00:01.000Z',
			'labels' => [],
		];
	}

	/** @return string[] the ids on the viewer's home timeline */
	private function home(Person $viewer): array {
		$this->streamRequest->setViewer($viewer);
		$options = (new ProbeOptions())->setFormat(ACore::FORMAT_ACTIVITYPUB)->setProbe(ProbeOptions::HOME)->setLimit(50);

		return array_map(static fn (Stream $stream): string => $stream->getId(), $this->streamRequest->getTimeline($options));
	}

	public function testAnAnonymousReaderDoesNotSeeItAndAFollowerDoes(): void {
		$follower = $this->person(self::FOLLOWER, 'loggedinonly-follower');
		$stranger = $this->person(self::STRANGER, 'loggedinonly-stranger');
		$this->follows($follower);
		$id = BlueskyIds::postId(self::DID, self::RKEY);

		$this->assertTrue(Server::get(PostStore::class)->storePost($this->postView()));

		$this->streamRequest->resetViewer();
		$stored = $this->streamRequest->getStreamById($id);
		$this->assertSame(Stream::TYPE_FOLLOWERS, $stored->getVisibility());
		$this->assertNotContains(ACore::CONTEXT_PUBLIC, array_merge($stored->getToAll(), $stored->getCcArray()), 'not addressed to the public');
		$this->assertNotContains(ACore::CONTEXT_PUBLIC, array_merge((array)($stored->exportAsActivityPub()['to'] ?? []), (array)($stored->exportAsActivityPub()['cc'] ?? [])), 'nor in what is federated');

		try {
			$this->streamRequest->getStreamById($id, true);
			$this->fail('served to an anonymous reader');
		} catch (StreamNotFoundException) {
		}

		$this->streamRequest->setViewer($stranger);
		try {
			$this->streamRequest->getStreamById($id, true);
			$this->fail('served to somebody who does not follow the author');
		} catch (StreamNotFoundException) {
		}
		$this->assertNotContains($id, $this->home($stranger));

		$this->streamRequest->setViewer($follower);
		$this->assertSame($id, $this->streamRequest->getStreamById($id, true)->getId(), 'a follower reads it');
		$this->assertContains($id, $this->home($follower), 'and has it on their home timeline');
	}
}
