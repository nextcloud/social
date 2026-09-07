<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\StreamAction;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Seeds real actors, follows and notes through the same Request classes production
 * uses, then asserts what each timeline actually RETURNS — home visibility through
 * the follow join, direct-message isolation, favourites/bookmarks through the
 * action join, hashtags through the tag join, and id-based pagination. The unit
 * suite mocks the query builder, and StreamFilterTest only proves the SQL parses;
 * this is the layer where a broken join silently empties (or leaks into) every
 * user's timeline while both stay green.
 *
 * All rows carry unique '-tlseed-' ids and are removed in tearDown, so the suite
 * is safe to run against a database that holds other data.
 */
class TimelineSeedTest extends TestCase {
	private const BASE = 'https://cloud.example.org/tlseed';
	private const VIEWER = self::BASE . '/users/viewer';
	private const OTHER = self::BASE . '/users/other';
	private const AUTHOR = 'https://remote.example/tlseed/users/author';
	private const AUTHOR_FOLLOWERS = self::AUTHOR . '/followers';
	private const TAG = 'tlseedtag';

	/** every note id the tests may create, so cleanup also catches aborted runs */
	private const SUFFIXES = [
		'home-followed', 'home-unrelated', 'home-pending', 'dm', 'liked', 'bookmarked',
		'tagged', 'untagged', 'page-1', 'page-2', 'page-3', 'acct-public', 'acct-dm',
	];

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private FollowsRequest $followsRequest;
	private StreamActionsRequest $streamActionsRequest;

	/** @var string[] stream ids created by the test, removed in tearDown */
	private array $streams = [];

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->followsRequest = Server::get(FollowsRequest::class);
		$this->streamActionsRequest = Server::get(StreamActionsRequest::class);
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
		$this->streams = [];
		foreach ([self::VIEWER, self::OTHER, self::AUTHOR] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
			$this->followsRequest->deleteRelatedId($id);
		}
	}

	private function person(string $id, string $username, bool $local): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername($username);
		$person->setAccount($username . ($local ? '@cloud.example.org' : '@remote.example'))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);

		return $person;
	}

	private function cachedPerson(string $id, string $username, bool $local = false): Person {
		$person = $this->person($id, $username, $local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	/**
	 * A note saved the way NoteInterface::save() persists it: the row plus its
	 * social_stream_dest recipient rows.
	 */
	private function note(
		string $suffix, string $attributedTo, string $to, array $cc = [], int $nid = 0,
	): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo($attributedTo);
		$note->setTo($to);
		$note->setCcArray($cc);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		if ($nid > 0) {
			$note->setNid($nid);
		}

		$this->streamRequest->save($note);
		$this->streams[] = $note->getId();

		return $note;
	}

	/** @return string[] the ids of the streams a probe returns */
	private function timeline(Person $viewer, string $probe, string $argument = ''): array {
		$this->streamRequest->setViewer($viewer);

		$options = new ProbeOptions();
		$options->setProbe($probe);
		$options->setLimit(40);
		if ($argument !== '') {
			$options->setArgument($argument);
		}

		return array_map(
			static fn ($stream): string => $stream->getId(),
			$this->streamRequest->getTimeline($options)
		);
	}

	public function testHomeShowsThePostsOfFollowedAuthorsAndOnlyThose(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'tlseed-viewer', true);
		$other = $this->cachedPerson(self::OTHER, 'tlseed-other', true);
		$this->cachedPerson(self::AUTHOR, 'tlseed-author');

		// viewer follows the author; "other" does not
		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/1');
		$follow->setActorId(self::VIEWER);
		$follow->setObjectId(self::AUTHOR);
		$follow->setFollowId(self::AUTHOR_FOLLOWERS);
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);

		$followed = $this->note('home-followed', self::AUTHOR, ACore::CONTEXT_PUBLIC, [self::AUTHOR_FOLLOWERS]);
		$unrelated = $this->note('home-unrelated', self::OTHER, ACore::CONTEXT_PUBLIC, [self::OTHER . '/followers']);

		$home = $this->timeline($viewer, ProbeOptions::HOME);
		$this->assertContains($followed->getId(), $home, 'a followed author\'s post reaches home');
		$this->assertNotContains($unrelated->getId(), $home, 'an unfollowed author\'s post does not');

		$otherHome = $this->timeline($other, ProbeOptions::HOME);
		$this->assertNotContains($followed->getId(), $otherHome, 'someone else\'s home stays empty of it');
	}

	public function testAnUnacceptedFollowDoesNotFillHome(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'tlseed-viewer', true);
		$this->cachedPerson(self::AUTHOR, 'tlseed-author');

		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/pending');
		$follow->setActorId(self::VIEWER);
		$follow->setObjectId(self::AUTHOR);
		$follow->setFollowId(self::AUTHOR_FOLLOWERS);
		$follow->setAccepted(false);
		$this->followsRequest->save($follow);

		$note = $this->note('home-pending', self::AUTHOR, ACore::CONTEXT_PUBLIC, [self::AUTHOR_FOLLOWERS]);

		$this->assertNotContains($note->getId(), $this->timeline($viewer, ProbeOptions::HOME));
	}

	public function testDirectMessagesAreOnlyVisibleToTheirRecipient(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'tlseed-viewer', true);
		$other = $this->cachedPerson(self::OTHER, 'tlseed-other', true);
		$this->cachedPerson(self::AUTHOR, 'tlseed-author');

		$dm = $this->note('dm', self::AUTHOR, self::VIEWER);

		$this->assertContains($dm->getId(), $this->timeline($viewer, ProbeOptions::DIRECT));
		$this->assertNotContains($dm->getId(), $this->timeline($other, ProbeOptions::DIRECT));
	}

	public function testFavouritesAndBookmarksAreDistinctPerViewerLists(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'tlseed-viewer', true);
		$other = $this->cachedPerson(self::OTHER, 'tlseed-other', true);
		$this->cachedPerson(self::AUTHOR, 'tlseed-author');

		$liked = $this->note('liked', self::AUTHOR, ACore::CONTEXT_PUBLIC);
		$bookmarked = $this->note('bookmarked', self::AUTHOR, ACore::CONTEXT_PUBLIC);

		$this->action(self::VIEWER, $liked->getId(), StreamAction::LIKED);
		$this->action(self::VIEWER, $bookmarked->getId(), StreamAction::BOOKMARKED);

		$favourites = $this->timeline($viewer, ProbeOptions::FAVOURITES);
		$bookmarks = $this->timeline($viewer, ProbeOptions::BOOKMARKS);

		$this->assertContains($liked->getId(), $favourites);
		$this->assertNotContains($bookmarked->getId(), $favourites, 'a bookmark is not a favourite');
		$this->assertContains($bookmarked->getId(), $bookmarks);
		$this->assertNotContains($liked->getId(), $bookmarks, 'a favourite is not a bookmark');

		$this->assertNotContains($liked->getId(), $this->timeline($other, ProbeOptions::FAVOURITES));
		$this->assertNotContains($bookmarked->getId(), $this->timeline($other, ProbeOptions::BOOKMARKS));
	}

	public function testHashtagTimelineReturnsTaggedNotes(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'tlseed-viewer', true);
		$this->cachedPerson(self::AUTHOR, 'tlseed-author');

		$tagged = new Note();
		$tagged->setId(self::BASE . '/notes/tagged');
		$tagged->setAttributedTo(self::AUTHOR);
		$tagged->setTo(ACore::CONTEXT_PUBLIC);
		$tagged->setContent('<p>#' . self::TAG . '</p>');
		$tagged->setPublishedTime(time());
		$tagged->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$tagged->setHashtags([self::TAG]);
		$this->streamRequest->save($tagged);
		$this->streams[] = $tagged->getId();

		$plain = $this->note('untagged', self::AUTHOR, ACore::CONTEXT_PUBLIC);

		$timeline = $this->timeline($viewer, ProbeOptions::HASHTAG, self::TAG);
		$this->assertContains($tagged->getId(), $timeline);
		$this->assertNotContains($plain->getId(), $timeline);
	}

	public function testPaginationCursorsFilterOnTheNumericId(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'tlseed-viewer', true);
		$this->cachedPerson(self::AUTHOR, 'tlseed-author');

		// nids far apart from anything a live database would hand out
		$base = (time() - 1000) * 1000;
		$oldest = $this->note('page-1', self::AUTHOR, self::VIEWER, [], $base + 1);
		$middle = $this->note('page-2', self::AUTHOR, self::VIEWER, [], $base + 2);
		$newest = $this->note('page-3', self::AUTHOR, self::VIEWER, [], $base + 3);

		$this->streamRequest->setViewer($viewer);

		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::DIRECT)->setLimit(40)->setMaxId($middle->getNid());
		$older = array_map(static fn ($s): string => $s->getId(), $this->streamRequest->getTimeline($options));
		$this->assertContains($oldest->getId(), $older, 'max_id returns strictly older posts');
		$this->assertNotContains($middle->getId(), $older);
		$this->assertNotContains($newest->getId(), $older);

		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::DIRECT)->setLimit(40)->setMinId($middle->getNid());
		$newer = array_map(static fn ($s): string => $s->getId(), $this->streamRequest->getTimeline($options));
		$this->assertContains($newest->getId(), $newer, 'min_id returns strictly newer posts');
		$this->assertNotContains($middle->getId(), $newer);
		$this->assertNotContains($oldest->getId(), $newer);
	}

	public function testAccountTimelineListsOnlyPublicPostsOfThatAccount(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'tlseed-viewer', true);
		$this->cachedPerson(self::AUTHOR, 'tlseed-author');

		$public = $this->note('acct-public', self::AUTHOR, ACore::CONTEXT_PUBLIC);
		$dm = $this->note('acct-dm', self::AUTHOR, self::OTHER);

		$this->streamRequest->setViewer($viewer);
		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::ACCOUNT)->setLimit(40);
		$options->setAccountId(self::AUTHOR);

		$ids = array_map(static fn ($s): string => $s->getId(), $this->streamRequest->getTimeline($options));
		$this->assertContains($public->getId(), $ids);
		$this->assertNotContains($dm->getId(), $ids, 'a DM never shows on the public account timeline');
	}

	private function action(string $actorId, string $streamId, string $key): void {
		$action = new StreamAction($actorId, $streamId);
		$action->updateValueBool($key, true);
		try {
			$existing = $this->streamActionsRequest->getAction($actorId, $streamId);
			$existing->updateValueBool($key, true);
			$this->streamActionsRequest->update($existing);
		} catch (\OCA\Social\Exceptions\StreamActionDoesNotExistException $e) {
			$this->streamActionsRequest->create($action);
		}
	}
}
