<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A page of the public timeline is `limit` posts a reader can be shown.
 *
 * The rows are read with their author's cached actor, which a post can be
 * without — its author was never fetched, or the row was swept. The page
 * used to be chosen without that join and read with it, so every such post
 * among the newest took a place on the page and then fell out of it: a page
 * of twenty came back with fewer, and a client reads a short page as the
 * end of the timeline.
 */
class PublicTimelineAuthorTest extends TestCase {
	private const BASE = 'https://cloud.example.org/public-author';
	private const VIEWER = self::BASE . '/users/viewer';
	private const KNOWN = 'https://known.test/users/author';
	private const UNKNOWN = 'https://unknown.test/users/author';
	private const KNOWN_POSTS = 3;
	private const UNKNOWN_POSTS = 2;

	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		for ($i = 0; $i < self::KNOWN_POSTS; $i++) {
			$this->streamRequest->deleteById(self::BASE . '/notes/known-' . $i, Note::TYPE);
		}
		for ($i = 0; $i < self::UNKNOWN_POSTS; $i++) {
			$this->streamRequest->deleteById(self::BASE . '/notes/unknown-' . $i, Note::TYPE);
		}
		foreach ([self::VIEWER, self::KNOWN, self::UNKNOWN] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
		}
	}

	private function cachedPerson(string $id, string $username, bool $local): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername($username);
		$person->setAccount($username . '@' . parse_url($id, PHP_URL_HOST))
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following')
			->setInbox($id . '/inbox')
			->setOutbox($id . '/outbox')
			->setLocal($local);
		$this->cacheActorsRequest->save($person);

		return $person;
	}

	private function note(string $id, string $author, int $publishedTime): void {
		$note = new Note();
		$note->setId($id)
			->setAttributedTo($author)
			->setTo(ACore::CONTEXT_PUBLIC)
			->setCcArray([$author . '/followers'])
			->setContent('<p>public</p>')
			->setPublishedTime($publishedTime)
			->setPublished(gmdate('Y-m-d\TH:i:s\Z', $publishedTime));
		$this->streamRequest->save($note);
	}

	public function testAPageOfThePublicTimelineIsFullWhenItsNewestPostsHaveNoCachedAuthor(): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'pubviewer', true);
		$this->cachedPerson(self::KNOWN, 'pubknown', false);

		$now = time();
		for ($i = 0; $i < self::KNOWN_POSTS; $i++) {
			$this->note(self::BASE . '/notes/known-' . $i, self::KNOWN, $now - 60 - $i);
		}
		// the newest of all, by an author with no cached actor
		for ($i = 0; $i < self::UNKNOWN_POSTS; $i++) {
			$this->note(self::BASE . '/notes/unknown-' . $i, self::UNKNOWN, $now - 10 - $i);
		}

		$this->streamRequest->setViewer($viewer);
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe(ProbeOptions::PUBLIC)
			->setLimit(self::KNOWN_POSTS);

		$page = $this->streamRequest->getTimeline($options);
		$authors = array_map(static fn (Stream $post): string => $post->getAttributedTo(), $page);

		$this->assertCount(self::KNOWN_POSTS, $page, 'a page of limit posts while there are enough to fill it');
		$this->assertNotContains(self::UNKNOWN, $authors);
	}
}
