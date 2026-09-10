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
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A boost carries the audience of the booster, not of the post it repeats, so
 * the row that joins the announced status into a timeline decides who reads
 * that status. The scenario is the one a remote server can drive on its own:
 * the reader follows the booster and nobody else, and the booster announces a
 * post they were only allowed to see because its author let them follow.
 *
 * All rows carry unique '-boostvis-' ids and are removed in tearDown, so the
 * suite is safe to run against a database that holds other data.
 */
class BoostVisibilityTest extends TestCase {
	private const BASE = 'https://cloud.example.org/boostvis';
	private const VIEWER = self::BASE . '/users/viewer';
	private const AUTHOR = 'https://remote.example/boostvis/users/author';
	private const AUTHOR_FOLLOWERS = self::AUTHOR . '/followers';
	private const BOOSTER = 'https://evil.example/boostvis/users/booster';
	private const BOOSTER_FOLLOWERS = self::BOOSTER . '/followers';

	/** every note id the tests may create, so cleanup also catches aborted runs */
	private const NOTES = ['open', 'private'];
	private const ANNOUNCES = ['open', 'private'];

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
		foreach (self::NOTES as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/notes/' . $suffix, Note::TYPE);
		}
		foreach (self::ANNOUNCES as $suffix) {
			$this->streamRequest->deleteById(self::BASE . '/announces/' . $suffix, Announce::TYPE);
		}
		foreach ([self::VIEWER, self::AUTHOR, self::BOOSTER] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
			$this->followsRequest->deleteRelatedId($id);
		}
	}

	private function cachedPerson(string $id, string $username, bool $local = false): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername($username);
		$person->setAccount($username . '@' . parse_url($id, PHP_URL_HOST))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	private function note(string $suffix, string $to): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo($to);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	/** The Announce as AnnounceInterface::save() stores it: public, cc the booster's followers. */
	private function announce(string $suffix, string $objectId): Announce {
		$announce = new Announce();
		$announce->setId(self::BASE . '/announces/' . $suffix);
		$announce->setAttributedTo(self::BOOSTER);
		$announce->setObjectId($objectId);
		$announce->setTo(ACore::CONTEXT_PUBLIC);
		$announce->setCcArray([self::BOOSTER_FOLLOWERS]);
		$announce->setPublishedTime(time());
		$announce->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($announce);

		return $announce;
	}

	private function follow(string $objectId, string $followId): void {
		$follow = new Follow();
		$follow->setId(self::BASE . '/follows/' . md5($objectId));
		$follow->setActorId(self::VIEWER);
		$follow->setObjectId($objectId);
		$follow->setFollowId($followId);
		$follow->setAccepted(true);
		$this->followsRequest->save($follow);
	}

	/** @return Stream[] the viewer's home timeline, by stream id */
	private function home(Person $viewer): array {
		$this->streamRequest->setViewer($viewer);

		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::HOME);
		$options->setLimit(40);

		$home = [];
		foreach ($this->streamRequest->getTimeline($options) as $stream) {
			$home[$stream->getId()] = $stream;
		}

		return $home;
	}

	public function testABoostCarriesAPublicPostButNotAFollowersOnlyOne(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'boostvis-viewer', true);
		$this->cachedPerson(self::AUTHOR, 'boostvis-author');
		$this->cachedPerson(self::BOOSTER, 'boostvis-booster');

		// the viewer follows the booster and nobody else — in particular not
		// the author of the posts being boosted
		$this->follow(self::BOOSTER, self::BOOSTER_FOLLOWERS);

		$open = $this->note('open', ACore::CONTEXT_PUBLIC);
		$private = $this->note('private', self::AUTHOR_FOLLOWERS);
		$boostOfOpen = $this->announce('open', $open->getId());
		$boostOfPrivate = $this->announce('private', $private->getId());

		$home = $this->home($viewer);

		$this->assertArrayHasKey($boostOfOpen->getId(), $home, 'a boost from a followed account reaches home');
		$this->assertTrue(
			$home[$boostOfOpen->getId()]->hasObject(),
			'and it carries the public post it repeats'
		);

		$this->assertArrayHasKey($boostOfPrivate->getId(), $home);
		$this->assertFalse(
			$home[$boostOfPrivate->getId()]->hasObject(),
			'a boost never widens the audience of the post it repeats'
		);
		$this->assertArrayNotHasKey($private->getId(), $home, 'nor does the post itself show up');
	}
}
