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
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `only_news` against the real database.
 *
 * `Stream::newsKindOf()` is tested on its own over strings; what this adds is
 * the half that only a database can be wrong about: that the answer is written
 * to `news_kind` on the way in, that an edit rewrites it, and that the
 * predicate on the way out matches what was written. A post can be classified
 * perfectly and still be missing from the timeline if those three disagree.
 */
class OnlyNewsTimelineTest extends TestCase {
	private const BASE = 'https://cloud.example.org/onlynews';
	private const VIEWER = self::BASE . '/users/viewer';
	private const AUTHOR = 'https://remote.example/onlynews/users/author';
	private const AUTHOR_FOLLOWERS = self::AUTHOR . '/followers';

	private const SUFFIXES = ['with-link', 'plain', 'article', 'mention-only', 'edited'];

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

	private function note(string $suffix, string $content, string $subType = ''): Note {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->setCcArray([self::AUTHOR_FOLLOWERS]);
		$note->setContent($content);
		if ($subType !== '') {
			$note->setSubType($subType);
		}
		$note->setPublishedTime(time());
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z'));
		$this->streamRequest->save($note);

		return $note;
	}

	/** @return string[] the ids of this test's own posts the timeline returns */
	private function home(Person $viewer, bool $onlyNews): array {
		$this->streamRequest->setViewer($viewer);

		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe(ProbeOptions::HOME)
			->setLimit(50)
			->setOnlyNews($onlyNews);

		return array_values(array_filter(
			array_map(
				static fn ($stream): string => $stream->getId(),
				$this->streamRequest->getTimeline($options)
			),
			static fn (string $id): bool => str_starts_with($id, self::BASE . '/notes/')
		));
	}

	private function seedFollowedAuthor(): Person {
		$viewer = $this->cachedPerson(self::VIEWER, 'onviewer', true);
		$this->cachedPerson(self::AUTHOR, 'onauthor', false);

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
		$this->note('with-link', '<p>read <a href="https://paper.example/piece">this</a></p>');
		$this->note('plain', '<p>good morning</p>');

		$home = $this->home($viewer, false);

		$this->assertContains(self::BASE . '/notes/with-link', $home);
		$this->assertContains(self::BASE . '/notes/plain', $home);
	}

	public function testOnlyNewsKeepsThePostsThatLinkSomewhere(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('with-link', '<p>read <a href="https://paper.example/piece">this</a></p>');
		$this->note('plain', '<p>good morning</p>');

		$this->assertSame([self::BASE . '/notes/with-link'], $this->home($viewer, true));
	}

	/**
	 * A blog post is news in its own right: it arrives as an `Article` and is
	 * stored as a `Note` carrying that word in `subtype`, with nothing in it
	 * that a link predicate would ever match.
	 */
	public function testAnArticleIsNewsWithoutLinkingAnywhere(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('article', '<p>a long read with no links in it</p>', 'Article');

		$this->assertSame([self::BASE . '/notes/article'], $this->home($viewer, true));
	}

	/**
	 * The trap the whole classifier exists for. A mention is an anchor, and an
	 * instance where every conversation counted as news would have a News
	 * timeline indistinguishable from the home one.
	 */
	public function testAConversationIsNotNews(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note(
			'mention-only',
			'<p><span class="h-card"><a href="https://remote.example/@bob" class="u-url mention">@bob</a></span> morning</p>'
		);

		$this->assertSame([], $this->home($viewer, true));
	}

	/**
	 * The column is derived from the content, so an edit has to rewrite it:
	 * otherwise a post that had its link taken out stays in the News timeline
	 * for good, and one that gained a link never reaches it.
	 */
	public function testAnEditMovesAPostInAndOutOfTheTimeline(): void {
		$viewer = $this->seedFollowedAuthor();
		$note = $this->note('edited', '<p>good morning</p>');

		$this->assertSame([], $this->home($viewer, true));

		$note->setContent('<p>read <a href="https://paper.example/piece">this</a></p>');
		$this->streamRequest->update($note);

		$this->assertSame([self::BASE . '/notes/edited'], $this->home($viewer, true));

		$note->setContent('<p>good morning</p>');
		$this->streamRequest->update($note);

		$this->assertSame([], $this->home($viewer, true));
	}

	/**
	 * News is not a kind of media, so the two narrowings are independent: a
	 * client that asks both gets the posts that are both, and a plain link
	 * post is not one of them.
	 */
	public function testNewsAndMediaNarrowTogetherRatherThanOneWinning(): void {
		$viewer = $this->seedFollowedAuthor();
		$this->note('with-link', '<p>read <a href="https://paper.example/piece">this</a></p>');

		$this->streamRequest->setViewer($viewer);
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe(ProbeOptions::HOME)
			->setLimit(50)
			->setOnlyMedia(true)
			->setOnlyNews(true);

		$ids = array_values(array_filter(
			array_map(
				static fn ($stream): string => $stream->getId(),
				$this->streamRequest->getTimeline($options)
			),
			static fn (string $id): bool => str_starts_with($id, self::BASE . '/notes/')
		));

		$this->assertSame([], $ids);
	}

	/** Every value the writer can produce is one the reader asks for. */
	public function testTheWriterAndTheReaderAgreeOnTheValues(): void {
		$this->assertContains(
			Stream::newsKindOf('<p>read <a href="https://paper.example/piece">this</a></p>'),
			[Stream::NEWS_KIND_LINK, Stream::NEWS_KIND_ARTICLE]
		);
	}
}
