<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\StreamActionService;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A page of bookmarks or favourites is `limit` posts a reader can be shown.
 *
 * The marked posts are read with their author's cached actor, which a post
 * can be without — its author was never fetched, or the row was swept. When
 * the page is chosen without that join, every such post among the newest
 * marks takes a place on the page and then falls out of it, and a client
 * reads the short page as the end of the list.
 */
class MarkedTimelineAuthorTest extends TestCase {
	private const BASE = 'https://cloud.example.org/marked-author';
	private const VIEWER = self::BASE . '/users/viewer';
	private const KNOWN = 'https://known.test/users/marked-author';
	private const UNKNOWN = 'https://unknown.test/users/marked-author';
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
		Server::get(StreamActionsRequest::class)->deleteByActor(self::VIEWER);
		foreach ($this->noteIds() as $id) {
			$this->streamRequest->deleteById($id, Note::TYPE);
		}
		foreach ([self::VIEWER, self::KNOWN, self::UNKNOWN] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
		}
	}

	/** @return string[] */
	private function noteIds(): array {
		$ids = [];
		for ($i = 0; $i < self::KNOWN_POSTS; $i++) {
			$ids[] = self::BASE . '/notes/known-' . $i;
		}
		for ($i = 0; $i < self::UNKNOWN_POSTS; $i++) {
			$ids[] = self::BASE . '/notes/unknown-' . $i;
		}

		return $ids;
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

	/** A public note by `$author`, marked by the viewer with `$mark`. */
	private function markedNote(string $id, string $author, int $publishedTime, string $mark): void {
		$note = new Note();
		$note->setId($id)
			->setAttributedTo($author)
			->setTo(ACore::CONTEXT_PUBLIC)
			->setCcArray([$author . '/followers'])
			->setContent('<p>public</p>')
			->setPublishedTime($publishedTime)
			->setPublished(gmdate('Y-m-d\TH:i:s\Z', $publishedTime));
		$this->streamRequest->save($note);
		Server::get(StreamActionService::class)->setActionBool(self::VIEWER, $id, $mark, true);
	}

	/** @return array<string, array{string, string}> */
	public static function marks(): array {
		return [
			'bookmarks' => [ProbeOptions::BOOKMARKS, StreamAction::BOOKMARKED],
			'favourites' => [ProbeOptions::FAVOURITES, StreamAction::LIKED],
		];
	}

	#[DataProvider('marks')]
	public function testAPageOfMarkedPostsIsFullWhenItsNewestPostsHaveNoCachedAuthor(string $probe, string $mark): void {
		$viewer = $this->cachedPerson(self::VIEWER, 'markedviewer', true);
		$this->cachedPerson(self::KNOWN, 'markedknown', false);

		$now = time();
		for ($i = 0; $i < self::KNOWN_POSTS; $i++) {
			$this->markedNote(self::BASE . '/notes/known-' . $i, self::KNOWN, $now - 60 - $i, $mark);
		}
		// the newest of all, by an author with no cached actor
		for ($i = 0; $i < self::UNKNOWN_POSTS; $i++) {
			$this->markedNote(self::BASE . '/notes/unknown-' . $i, self::UNKNOWN, $now - 10 - $i, $mark);
		}

		$this->streamRequest->setViewer($viewer);
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe($probe)
			->setLimit(self::KNOWN_POSTS);

		$page = $this->streamRequest->getTimeline($options);
		$authors = array_map(static fn (Stream $post): string => $post->getAttributedTo(), $page);

		$this->assertCount(self::KNOWN_POSTS, $page, 'a page of limit posts while there are enough to fill it');
		$this->assertNotContains(self::UNKNOWN, $authors);
	}
}
