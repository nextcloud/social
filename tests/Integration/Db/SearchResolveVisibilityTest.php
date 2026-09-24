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
use OCA\Social\Service\SearchService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Resolving a post by its address, on behalf of somebody.
 *
 * `resolveStatus()` is what `search?resolve=true` falls back to when the
 * full-text search finds nothing — and the full-text search is viewer-scoped,
 * so a post the reader may not see is exactly the case that reaches it. Asked
 * without a viewer, it answered with the post in full.
 *
 * The other caller, `RelayService`, is asking whether the *instance* holds an
 * object at all, on nobody's behalf, so the unscoped question has to keep
 * working too. Both are asserted here.
 *
 * All rows carry unique '-resolvevis-' ids and are removed in tearDown.
 */
class SearchResolveVisibilityTest extends TestCase {
	private const BASE = 'https://cloud.example.org/resolvevis';
	private const AUTHOR = self::BASE . '/users/author';
	private const AUTHOR_FOLLOWERS = self::AUTHOR . '/followers';
	private const STRANGER = 'https://remote.example/resolvevis/users/stranger';

	private const NOTES = ['open', 'followers-only'];

	private SearchService $searchService;
	private StreamRequest $streamRequest;
	private CacheActorsRequest $cacheActorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->searchService = Server::get(SearchService::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
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
		foreach ([self::AUTHOR, self::STRANGER] as $id) {
			$this->cacheActorsRequest->deleteCacheById($id);
		}
	}

	private function person(string $id, string $username, bool $local = false): Person {
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

	private function note(string $suffix, string $to): string {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo($to);
		$note->setContent('<p>' . $suffix . '</p>');
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note->getId();
	}

	private function seed(): void {
		$this->person(self::AUTHOR, 'author', true);
		$this->note('open', ACore::CONTEXT_PUBLIC);
		$this->note('followers-only', self::AUTHOR_FOLLOWERS);
	}

	/**
	 * The one that matters: a reader who follows nobody pastes the address of
	 * a followers-only post and is told there is nothing there.
	 */
	public function testAReaderCannotResolveAPostTheyMayNotSee(): void {
		$this->seed();
		$this->streamRequest->setViewer($this->person(self::STRANGER, 'stranger'));

		$this->assertNull(
			$this->searchService->resolveStatus(self::BASE . '/notes/followers-only', true),
			'a followers-only post resolved for somebody it was never sent to'
		);
	}

	/** And the same reader still resolves what was published to everybody. */
	public function testAReaderStillResolvesAPublicPost(): void {
		$this->seed();
		$this->streamRequest->setViewer($this->person(self::STRANGER, 'stranger'));

		$resolved = $this->searchService->resolveStatus(self::BASE . '/notes/open', true);

		$this->assertNotNull($resolved);
		$this->assertSame(self::BASE . '/notes/open', $resolved->getId());
	}

	/**
	 * The relay asks whether this instance holds the object at all, for nobody,
	 * and must keep getting a straight answer — otherwise a followers-only post
	 * would be fetched again every time one is announced.
	 */
	public function testTheUnscopedQuestionStillAnswersForTheServerItself(): void {
		$this->seed();
		$this->streamRequest->resetViewer();

		$resolved = $this->searchService->resolveStatus(self::BASE . '/notes/followers-only');

		$this->assertNotNull($resolved);
		$this->assertSame(self::BASE . '/notes/followers-only', $resolved->getId());
	}
}
