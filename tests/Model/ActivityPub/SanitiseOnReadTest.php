<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Model\TActivityPubMocks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../TActivityPubMocks.php';

/**
 * Remote HTML is made safe on the way into the model and again on the way
 * out of the database.
 *
 * `Note::import()` runs a remote `content` through `HtmlSanitizer`, so an
 * object exported before it is ever stored is as safe as a stored one;
 * `importFromDatabase()` runs `content` through it and `summary` through the
 * plain-text filter, so a row written under older rules is served under the
 * current ones. These tests hand a row full of markup to each export a post
 * or an account goes out through.
 */
class SanitiseOnReadTest extends TestCase {
	use TActivityPubMocks;

	private const HOSTILE_HTML = '<p onclick="steal()">hello<script>alert(1)</script>'
		. '<a href="javascript:alert(2)">link</a><img src="x" onerror="alert(3)">'
		. '<iframe src="https://evil.example/"></iframe><a href="data:text/html,x">d</a>'
		. '<span class="mention" style="position:fixed">@bob</span></p>';
	private const HOSTILE_TEXT = '<b>careful</b><img src=x onerror=alert(4)> &lt;script&gt;alert(5)&lt;/script&gt;';

	protected function setUp(): void {
		$this->installActivityPub();
	}

	protected function tearDown(): void {
		Stream::resetReplyParentCache();
		Stream::resetQuoteCache();
		AP::set(null);
	}

	/** @return array<string, array{class-string<Stream>}> */
	public static function postTypes(): array {
		return ['a note' => [Note::class], 'a poll' => [Question::class]];
	}

	#[DataProvider('postTypes')]
	public function testAPostReadBackIsServedSanitisedToClients(string $class): void {
		$exported = $this->storedPost($class)->exportAsLocal();

		self::assertSafeHtml($exported['content']);
		$this->assertStringContainsString('hello', $exported['content']);
		self::assertPlainText($exported['spoiler_text']);
		$this->assertStringContainsString('careful', $exported['spoiler_text']);
	}

	#[DataProvider('postTypes')]
	public function testAPostReadBackIsServedSanitisedToPeers(string $class): void {
		$post = $this->storedPost($class);
		$post->setExportFormat(ACore::FORMAT_ACTIVITYPUB);
		$exported = $post->exportAsActivityPub();

		self::assertSafeHtml($exported['content']);
		self::assertSafeHtml($exported['contentMap']['en']);
		self::assertPlainText($exported['summary']);
		self::assertPlainText($exported['summaryMap']['en']);
	}

	/** The raw wire object is kept in `source`, and is never what is served. */
	public function testTheRawSourceDoesNotReachTheExports(): void {
		$post = $this->storedPost(Note::class);
		$this->assertStringContainsString('onclick', $post->getSource(), 'the row keeps the original');

		$this->assertStringNotContainsString('onclick', (string)json_encode($post->exportAsLocal()));
		$post->setExportFormat(ACore::FORMAT_ACTIVITYPUB);
		$this->assertStringNotContainsString('onclick', (string)json_encode($post->exportAsActivityPub()));
	}

	/**
	 * A post taken in from a peer is sanitised as it is imported, so the
	 * in-memory object — exported for an edit, a notification or a search
	 * that resolved it — is safe before it is ever stored. The plain-text
	 * summary is kept as sent and escaped by whatever renders it.
	 */
	#[DataProvider('postTypes')]
	public function testAPostTakenInFromAPeerIsSanitisedOnImport(string $class): void {
		$post = new $class();
		$post->import([
			'id' => 'https://remote.example/notes/1',
			'type' => ($class === Question::class) ? 'Question' : 'Note',
			'content' => self::HOSTILE_HTML,
			'summary' => self::HOSTILE_TEXT,
		]);

		self::assertSafeHtml($post->getContent());
		$this->assertStringContainsString('hello', $post->getContent());
		self::assertSafeHtml($post->exportAsLocal()['content']);
		$this->assertSame(self::HOSTILE_TEXT, $post->getSummary());
	}

	/** Sanitising on import and again on read leaves the text as it was after the first pass. */
	public function testAPostSanitisedOnImportIsUnchangedWhenReadBack(): void {
		$note = new Note();
		$note->import([
			'id' => 'https://remote.example/notes/1',
			'type' => 'Note',
			'content' => '<p>a &amp; b &lt; c <a href="https://remote.example/@bob" class="u-url mention">@<span>bob</span></a></p>',
		]);

		$read = new Note();
		$read->importFromDatabase(['id' => 'https://remote.example/notes/1', 'type' => 'Note', 'content' => $note->getContent()]);

		$this->assertSame($note->getContent(), $read->getContent());
		$this->assertStringContainsString('a &amp; b &lt; c', $read->getContent());
	}

	public function testARemoteAccountReadBackIsServedSanitisedToClients(): void {
		$person = new Person();
		$person->importFromDatabase([
			'nid' => '7',
			'id' => 'https://remote.example/users/bob',
			'type' => 'Person',
			'preferred_username' => 'bob',
			'account' => 'bob@remote.example',
			'name' => self::HOSTILE_TEXT,
			'summary' => self::HOSTILE_HTML,
			'source' => json_encode(['type' => 'Person', 'summary' => self::HOSTILE_HTML]),
		]);

		$exported = $person->exportAsLocal();

		self::assertSafeHtml($exported['note']);
		$this->assertStringContainsString('hello', $exported['note']);
		self::assertPlainText($exported['display_name']);
		$this->assertStringContainsString('careful', $exported['display_name']);
	}

	/**
	 * Alt text is the one remote text that is made plain on the way in rather
	 * than on the way out: the attachment column holds what `Document`
	 * import produced.
	 */
	public function testARemoteAttachmentsAltTextArrivesAsPlainText(): void {
		$document = new Document();
		$document->import([
			'id' => 'https://remote.example/files/a',
			'type' => 'Document',
			'mediaType' => 'image/png',
			'url' => 'https://remote.example/files/a.png',
			'name' => self::HOSTILE_TEXT,
		]);

		self::assertPlainText($document->getDescription());
		$this->assertStringContainsString('careful', $document->getDescription());
	}

	/** @param class-string<Stream> $class */
	private function storedPost(string $class): Stream {
		$wire = [
			'id' => 'https://remote.example/notes/1',
			'type' => ($class === Question::class) ? 'Question' : 'Note',
			'content' => self::HOSTILE_HTML,
			'summary' => self::HOSTILE_TEXT,
			'contentMap' => ['en' => self::HOSTILE_HTML],
			'oneOf' => [['type' => 'Note', 'name' => 'yes', 'replies' => ['totalItems' => 1]]],
		];

		$post = new $class();
		$post->importFromDatabase([
			'nid' => '5',
			'id' => $wire['id'],
			'type' => $wire['type'],
			'content' => self::HOSTILE_HTML,
			'summary' => self::HOSTILE_TEXT,
			'language' => 'en',
			'attributed_to' => 'https://remote.example/users/bob',
			'actor_id' => 'https://remote.example/users/bob',
			'published_time' => '2026-01-01 00:00:00',
			'source' => json_encode($wire),
		]);

		return $post;
	}

	private static function assertSafeHtml(string $html): void {
		foreach (['<script', '<img', '<iframe', 'onclick', 'onerror', 'javascript:', 'data:', 'style='] as $needle) {
			self::assertStringNotContainsStringIgnoringCase($needle, $html);
		}
	}

	private static function assertPlainText(string $text): void {
		self::assertDoesNotMatchRegularExpression('/<[a-z!\/]/i', $text, 'markup survived in a plain-text field');
	}
}
