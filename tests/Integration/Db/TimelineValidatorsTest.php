<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The cheap probes a client's thirty-second poll is answered from, against
 * the real database: the newest nid of a set of collections and of a home
 * timeline, which the timeline's entity tag is built from, and the unread
 * notification badge. A probe that answers differently from the page it
 * stands for either hides new posts behind a 304 or never lets a client
 * cache anything.
 */
class TimelineValidatorsTest extends TestCase {
	private const BASE = 'https://cloud.example.org/tlval';
	private const VIEWER = self::BASE . '/users/viewer';
	private const LURKER = self::BASE . '/users/lurker';
	private const AUTHOR = 'https://remote.example/tlval/users/author';
	private const UNFOLLOWED = 'https://remote.example/tlval/users/unfollowed';

	private const SUFFIXES = ['old', 'new', 'own', 'unfollowed', 'n1', 'n2', 'n3', 'other'];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;

	/** @var array<string, string> suffix => nid */
	private array $nids = [];

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->cleanup();

		foreach ([self::VIEWER, self::LURKER, self::AUTHOR, self::UNFOLLOWED] as $id) {
			$this->cacheActorsRequest->save($this->person($id));
		}
		foreach ([self::VIEWER, self::LURKER] as $follower) {
			$follow = new Follow();
			$follow->setId($follower . '/follows/author');
			$follow->setActorId($follower);
			$follow->setObjectId(self::AUTHOR);
			$follow->setFollowId(self::AUTHOR . '/followers');
			$follow->setAccepted(true);
			$this->followsRequest->save($follow);
		}
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById($this->id($suffix));
		}
		foreach ([self::VIEWER, self::LURKER, self::AUTHOR, self::UNFOLLOWED] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
			$this->followsRequest->deleteRelatedId($id);
		}
	}

	private function id(string $suffix): string {
		return self::BASE . '/notes/' . $suffix;
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername(basename($id));
		$person->setAccount(basename($id) . '@' . parse_url($id, PHP_URL_HOST))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal(str_starts_with($id, self::BASE));

		return $person;
	}

	/** Published `$age` seconds ago: a nid is the publication second with a random suffix. */
	private function post(string $suffix, string $author, int $age): void {
		$note = new Note();
		$note->setId($this->id($suffix));
		$note->setAttributedTo($author);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([$author . '/followers']);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime(time() - $age);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() - $age));
		$this->save($suffix, $note);
	}

	private function notification(string $suffix, string $to, int $age): void {
		$notification = new SocialAppNotification();
		$notification->setId($this->id($suffix));
		$notification->setAttributedTo(self::AUTHOR)
			->setSubType(Stream::SUBTYPE_STATUS)
			->setObjectId($this->id('old'))
			->setTo($to)
			->setLocal(true);
		$notification->setPublishedTime(time() - $age);
		$notification->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() - $age));
		$this->save($suffix, $notification);
	}

	private function save(string $suffix, Stream $stream): void {
		$this->streamRequest->save($stream);
		$this->nids[$suffix] = (string)$this->streamRequest->getStreamById($stream->getId(), false, ACore::FORMAT_LOCAL)->getNid();
	}

	public function testTheNewestNidOfACollectionIsItsNewestRecipientRow(): void {
		$this->post('old', self::AUTHOR, 30);
		$this->post('new', self::AUTHOR, 20);

		$followers = md5(self::AUTHOR . '/followers');
		$this->assertSame($this->nids['new'], $this->streamRequest->newestNidFor([$followers]));
		$this->assertSame($this->nids['new'], $this->streamRequest->newestNidFor([$followers, md5(self::LURKER . '/followers')]));
		$this->assertSame('0', $this->streamRequest->newestNidFor([$followers], 'dm'), 'another kind of row');
		$this->assertSame('0', $this->streamRequest->newestNidFor([md5(self::LURKER . '/followers')]));
		$this->assertSame('0', $this->streamRequest->newestNidFor([]));
	}

	/**
	 * What the viewer follows and what the viewer posted, and nothing from an
	 * account they do not follow, however new.
	 */
	public function testTheNewestHomeNidIsTheNewestPostTheHomeTimelineHolds(): void {
		$this->post('old', self::AUTHOR, 40);
		$this->post('new', self::AUTHOR, 30);
		$this->post('own', self::VIEWER, 20);
		$this->post('unfollowed', self::UNFOLLOWED, 10);

		$this->assertSame($this->nids['own'], $this->streamRequest->newestHomeNid($this->person(self::VIEWER)));
		$this->assertSame($this->nids['new'], $this->streamRequest->newestHomeNid($this->person(self::LURKER)));
		$this->assertSame('0', $this->streamRequest->newestHomeNid($this->person(self::UNFOLLOWED . '-nobody')));
	}

	/** Only the account's own notifications, after the marker, cut one past the cap. */
	public function testTheUnreadBadgeCountsTheNotificationsAfterTheMarker(): void {
		$this->notification('n1', self::VIEWER, 30);
		$this->notification('n2', self::VIEWER, 20);
		$this->notification('n3', self::VIEWER, 10);
		$this->notification('other', self::LURKER, 10);
		$viewer = $this->person(self::VIEWER);

		$this->assertSame(3, $this->streamRequest->countNotificationsSince($viewer, '0'));
		$this->assertSame(2, $this->streamRequest->countNotificationsSince($viewer, $this->nids['n1']));
		$this->assertSame(0, $this->streamRequest->countNotificationsSince($viewer, $this->nids['n3']));
		$this->assertSame(2, $this->streamRequest->countNotificationsSince($viewer, '0', 1), 'past the cap, "more than one"');
		$this->assertSame(1, $this->streamRequest->countNotificationsSince($this->person(self::LURKER), '0'));
	}
}
