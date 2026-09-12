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
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `only_video` against the real database.
 *
 * The Videos view is a timeline with one predicate on it, and the predicate is
 * a substring test over a JSON text column -- it has to be, because it runs on
 * MySQL, PostgreSQL and SQLite alike and none of their JSON functions are the
 * same. A test over a mock would assert nothing about the part that can be
 * wrong, so this one runs where the SQL does.
 *
 * The two halves are both asserted: an attachment whose Mastodon type is
 * `video`, and a post that arrived as a PeerTube `Video`. So is the thing the
 * substring test could plausibly get wrong -- a photo post whose *alt text*
 * talks about video.
 */
class OnlyVideoTimelineTest extends TestCase {
	private const BASE = 'https://cloud.example.org/onlyvideo';
	private const VIEWER = self::BASE . '/users/viewer';
	private const AUTHOR = 'https://remote.example/onlyvideo/users/author';
	private const AUTHOR_FOLLOWERS = self::AUTHOR . '/followers';

	private const SUFFIXES = ['with-video', 'with-photo', 'no-media', 'peertube', 'alt-text-says-video'];

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
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::SUFFIXES as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix, Note::TYPE);
		}
		foreach ([self::VIEWER, self::AUTHOR] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
			$this->followsRequest->deleteRelatedId($id);
		}
	}

	private function cachedPerson(string $id, string $username, bool $local): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . ($local ? '@cloud.example.org' : '@remote.example'))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	private function attachment(string $id, string $type, string $description = ''): MediaAttachment {
		$media = new MediaAttachment();
		$media->setId($id);
		$media->setType($type);
		$media->setMediaType($type . '/mp4');
		$media->setUrl(self::BASE . '/media/' . $id);
		$media->setPreviewUrl(self::BASE . '/media/' . $id);
		$media->setDescription($description);

		return $media;
	}

	/** @param MediaAttachment[] $attachments */
	private function note(string $suffix, array $attachments, string $subType = ''): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([self::AUTHOR_FOLLOWERS]);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setAttachments($attachments);
		if ($subType !== '') {
			$note->setSubType($subType);
		}
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	/** @return string[] the ids of this test's own posts the timeline returns */
	private function home(Person $viewer, bool $onlyVideo): array {
		$this->streamRequest->setViewer($viewer);

		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe(ProbeOptions::HOME)
			->setLimit(50)
			->setOnlyVideo($onlyVideo);

		return array_values(array_filter(
			array_map(
				static fn ($stream): string => $stream->getId(),
				$this->streamRequest->getTimeline($options)
			),
			static fn (string $id): bool => str_starts_with($id, self::BASE . '/notes/')
		));
	}

	private function seedFollowedAuthor(): Person {
		$viewer = $this->cachedPerson(self::VIEWER, 'ovviewer', true);
		$this->cachedPerson(self::AUTHOR, 'ovauthor', false);

		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/1');
		$follow->setActorId(self::VIEWER);
		$follow->setObjectId(self::AUTHOR);
		$follow->setFollowId(self::AUTHOR_FOLLOWERS);
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);

		return $viewer;
	}

	public function testWithoutTheFilterEverythingIsStillThere(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('with-video', [$this->attachment('v1', 'video')]);
		$this->note('with-photo', [$this->attachment('p1', 'image')]);
		$this->note('no-media', []);

		$home = $this->home($viewer, false);

		$this->assertContains(self::BASE . '/notes/with-video', $home);
		$this->assertContains(self::BASE . '/notes/with-photo', $home);
		$this->assertContains(self::BASE . '/notes/no-media', $home);
	}

	public function testOnlyVideoKeepsThePostsCarryingAVideo(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('with-video', [$this->attachment('v1', 'video')]);
		$this->note('with-photo', [$this->attachment('p1', 'image')]);
		$this->note('no-media', []);

		$this->assertSame([self::BASE . '/notes/with-video'], $this->home($viewer, true));
	}

	/**
	 * A PeerTube video is a `Note` whose `subtype` says what it arrived as, and
	 * it belongs on the Videos timeline whether or not this instance found a
	 * file in it a browser can play -- an HLS-only instance, or one that
	 * published nothing but a torrent, is exactly the case worth reporting.
	 */
	public function testAPeerTubeVideoCountsEvenWithNothingPlayable(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('peertube', [], 'Video');

		$this->assertSame([self::BASE . '/notes/peertube'], $this->home($viewer, true));
	}

	/**
	 * The predicate looks for `"type":"video"` in the stored JSON, and a `"`
	 * inside a *value* is escaped as `\"` by `json_encode` -- so the seven
	 * characters cannot occur anywhere but in the field this is asking about.
	 * A photo whose alt text is the literal string proves it.
	 */
	public function testAPhotoWhoseAltTextLooksLikeTheFilterIsNotAVideo(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('alt-text-says-video', [$this->attachment('p2', 'image', '"type":"video"')]);

		$this->assertSame([], $this->home($viewer, true));
	}

	/** Every video is media, so the narrower of the two decides. */
	public function testOnlyVideoWinsOverOnlyMediaWhenBothAreAsked(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('with-video', [$this->attachment('v1', 'video')]);
		$this->note('with-photo', [$this->attachment('p1', 'image')]);

		$this->streamRequest->setViewer($viewer);
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe(ProbeOptions::HOME)
			->setLimit(50)
			->setOnlyMedia(true)
			->setOnlyVideo(true);

		$ids = array_values(array_filter(
			array_map(
				static fn ($stream): string => $stream->getId(),
				$this->streamRequest->getTimeline($options)
			),
			static fn (string $id): bool => str_starts_with($id, self::BASE . '/notes/')
		));

		$this->assertSame([self::BASE . '/notes/with-video'], $ids);
	}
}
