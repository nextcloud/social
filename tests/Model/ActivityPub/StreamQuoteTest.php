<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../TActivityPubMocks.php';

/**
 * Quote posts (FEP-044f, Mastodon 4.5) as the Stream model carries them:
 * parsed off the wire, re-read from the stored wire object, and exported both
 * ways.
 */
class StreamQuoteTest extends TestCase {
	use TActivityPubMocks;

	private const QUOTED = 'https://mastodon.social/users/bob/statuses/111';
	private const QUOTING = 'https://mastodon.social/users/alice/statuses/222';

	protected function setUp(): void {
		$this->installActivityPub();
		\OC::$server->register(IURLGenerator::class, $this->createMock(IURLGenerator::class));
	}

	protected function tearDown(): void {
		Stream::resetQuoteCache();
		AP::set(null);
		\OC::$server->reset();
	}

	/** The quoting note as Mastodon 4.5 sends it, with the key under test. */
	private function incoming(array $extra): Stream {
		$stream = new Stream();
		$stream->import(array_merge([
			'id' => self::QUOTING,
			'type' => 'Note',
			'attributedTo' => 'https://mastodon.social/users/alice',
			'content' => '<p>look at this</p>',
			'to' => [ACore::CONTEXT_PUBLIC],
		], $extra));

		return $stream;
	}

	/** The quoted post as it comes back from storage in client format. */
	private function storedQuoted(): Note {
		$author = new Person();
		$author->setId('https://mastodon.social/users/bob');
		$author->setNid(3);
		$author->setAccount('bob@mastodon.social');

		$quoted = new Note();
		$quoted->setId(self::QUOTED);
		$quoted->setNid(11);
		$quoted->setActor($author);
		$quoted->setContent('<p>the original</p>');
		$quoted->setExportFormat(ACore::FORMAT_LOCAL);

		return $quoted;
	}

	/** A StreamRequest that hands back the quoted post, and nothing else. */
	private function holdingTheQuoted(): void {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id, bool $asViewer = false, int $format = ACore::FORMAT_ACTIVITYPUB): Stream {
				if ($id === self::QUOTED && $asViewer && $format === ACore::FORMAT_LOCAL) {
					return $this->storedQuoted();
				}

				throw new StreamNotFoundException();
			});
		\OC::$server->register(StreamRequest::class, $streamRequest);
	}

	private function holdingNothing(): void {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->method('getStreamById')->willThrowException(new StreamNotFoundException());
		\OC::$server->register(StreamRequest::class, $streamRequest);
	}

	// --- parsing the wire object

	public function testTheFep044fQuoteIsReadOffAnIncomingNote(): void {
		$this->assertSame(self::QUOTED, $this->incoming(['quote' => self::QUOTED])->getQuote());
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function quoteAliasProvider(): array {
		return [
			// what Mastodon 4.5 emits alongside `quote`, for older readers
			'quoteUrl' => [['quoteUrl' => self::QUOTED]],
			'_misskey_quote' => [['_misskey_quote' => self::QUOTED]],
			'quoteUri' => [['quoteUri' => self::QUOTED]],
			'an embedded object' => [['quote' => ['id' => self::QUOTED, 'type' => 'Note']]],
			'a link' => [['quote' => ['href' => self::QUOTED, 'type' => 'Link']]],
		];
	}

	#[DataProvider('quoteAliasProvider')]
	public function testTheLegacyQuoteAliasesAreReadToo(array $wire): void {
		$this->assertSame(self::QUOTED, $this->incoming($wire)->getQuote());
	}

	public function testANoteThatQuotesNothingHasNoQuote(): void {
		$this->assertSame('', $this->incoming([])->getQuote());
	}

	public function testTheQuoteAuthorizationIsReadOffAnIncomingNote(): void {
		$stream = $this->incoming([
			'quote' => self::QUOTED,
			'quoteAuthorization' => 'https://mastodon.social/users/bob/approvals/1',
		]);

		$this->assertSame('https://mastodon.social/users/bob/approvals/1', $stream->getQuoteAuthorization());
	}

	/**
	 * The quote also rides in the stored wire object, and is read back out of it
	 * for a row written before `Version1000Date20260912000003` gave it a column
	 * — the fallback `Stream::importFromDatabase()` keeps for exactly that, and
	 * the only path a row the backfill has not reached is read through.
	 */
	public function testTheQuoteIsReadBackOutOfTheStoredWireObject(): void {
		$stream = new Stream();
		$stream->importFromDatabase([
			'id' => self::QUOTING,
			'nid' => 22,
			'source' => json_encode([
				'id' => self::QUOTING,
				'type' => 'Note',
				'quote' => self::QUOTED,
				'quoteAuthorization' => 'https://mastodon.social/users/bob/approvals/1',
			]),
		]);

		$this->assertSame(self::QUOTED, $stream->getQuote());
		$this->assertSame('https://mastodon.social/users/bob/approvals/1', $stream->getQuoteAuthorization());
	}

	/** A quoting post whose quote the quoted author has approved. */
	private function approved(): Stream {
		$stream = $this->incoming([
			'quote' => self::QUOTED,
			'quoteAuthorization' => 'https://mastodon.social/users/bob/approvals/1',
		]);
		$stream->setNid(22);

		return $stream;
	}

	// --- the client entity

	public function testAnApprovedQuoteOfAPostWeHoldIsAcceptedAndCarriesIt(): void {
		$this->holdingTheQuoted();
		$stream = $this->approved();

		$quote = $stream->exportAsLocal()['quote'];

		$this->assertSame(Stream::QUOTE_ACCEPTED, $quote['state']);
		$this->assertSame('11', $quote['quoted_status']['id']);
		$this->assertSame('<p>the original</p>', $quote['quoted_status']['content']);
	}

	/**
	 * Holding the quoted post says we *could* show it, which is not the same
	 * question as whether its author said we may. Reading the state off the
	 * lookup reported every quote accepted the moment it was written — before
	 * the author had answered, and whatever they answered.
	 */
	public function testAQuoteNobodyHasApprovedIsPendingEvenWhenWeHoldThePost(): void {
		$this->holdingTheQuoted();
		$stream = $this->incoming(['quote' => self::QUOTED]);
		$stream->setNid(22);

		$this->assertSame(
			['state' => Stream::QUOTE_PENDING, 'quoted_status' => null],
			$stream->exportAsLocal()['quote']
		);
	}

	/** Approved and queued for fetching: not here yet, so nothing to carry. */
	public function testAnApprovedQuoteOfAPostWeHaveNotFetchedYetCarriesNothing(): void {
		$this->holdingNothing();
		$stream = $this->approved();

		$this->assertSame(
			['state' => Stream::QUOTE_ACCEPTED, 'quoted_status' => null],
			$stream->exportAsLocal()['quote']
		);
	}

	public function testAStatusThatQuotesNothingHasANullQuote(): void {
		$stream = $this->incoming([]);
		$stream->setNid(22);

		$this->assertNull($stream->exportAsLocal()['quote']);
	}

	/**
	 * A quote of a post the reader may not see must not hand it over: the
	 * lookup is made as the viewer, which is the rule the boosted-object join
	 * applies to an Announce of a followers-only post.
	 */
	public function testAQuotedPostTheViewerMayNotSeeIsNotLeaked(): void {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->expects($this->once())
			->method('getStreamById')
			->with(self::QUOTED, true, ACore::FORMAT_LOCAL)
			->willThrowException(new StreamNotFoundException());
		\OC::$server->register(StreamRequest::class, $streamRequest);

		$quote = $this->approved()->exportAsLocal()['quote'];

		// accepted, because the author did approve it; the post is simply not
		// this reader's to see. Saying `pending` here would report the author
		// as not having answered when they have
		$this->assertSame(Stream::QUOTE_ACCEPTED, $quote['state']);
		$this->assertNull($quote['quoted_status']);
	}

	/** A refusal that was recorded is what the client is told, post or no post. */
	public function testARejectedQuoteIsExportedAsRejectedWithoutThePost(): void {
		$this->holdingTheQuoted();
		$stream = $this->incoming(['quote' => self::QUOTED]);
		$stream->setNid(22);
		$stream->setQuoteState(Stream::QUOTE_REJECTED);

		$this->assertSame(
			['state' => Stream::QUOTE_REJECTED, 'quoted_status' => null],
			$stream->exportAsLocal()['quote']
		);
	}

	/**
	 * A quote of a quote of a quote is a chain of database lookups and of
	 * nested entities; Mastodon stops at the first level and so does this.
	 */
	public function testANestedQuoteIsNotResolvedAgain(): void {
		$quoted = $this->storedQuoted();
		$quoted->setQuote('https://mastodon.social/users/carol/statuses/1');

		$quoted->setQuoteAuthorization('https://mastodon.social/users/carol/approvals/9');

		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->expects($this->once())->method('getStreamById')->willReturn($quoted);
		\OC::$server->register(StreamRequest::class, $streamRequest);

		$quote = $this->approved()->exportAsLocal()['quote'];

		$this->assertSame(Stream::QUOTE_ACCEPTED, $quote['state']);
		// approved at the next level down too, and still not resolved: the
		// chain stops here whatever the answer was
		$this->assertSame(
			['state' => Stream::QUOTE_ACCEPTED, 'quoted_status' => null],
			$quote['quoted_status']['quote']
		);
	}

	// --- the wire object we emit

	public function testAQuoteFederatesWithTheLegacyAliasesBeside(): void {
		$stream = new Note();
		$stream->setId(self::QUOTING);
		$stream->setQuote(self::QUOTED);
		$stream->setQuoteAuthorization('https://mastodon.social/users/bob/approvals/1');

		$wire = $stream->exportAsActivityPub();

		// Mastodon reads `quote`; anything older reads one of the two aliases
		$this->assertSame(self::QUOTED, $wire['quote']);
		$this->assertSame(self::QUOTED, $wire['quoteUrl']);
		$this->assertSame(self::QUOTED, $wire['_misskey_quote']);
		$this->assertSame('https://mastodon.social/users/bob/approvals/1', $wire['quoteAuthorization']);
	}

	public function testAPostThatQuotesNothingFederatesNoQuoteKeys(): void {
		$wire = (new Note())->setId(self::QUOTING)->exportAsActivityPub();

		$this->assertArrayNotHasKey('quote', $wire);
		$this->assertArrayNotHasKey('quoteUrl', $wire);
		$this->assertArrayNotHasKey('_misskey_quote', $wire);
		$this->assertArrayNotHasKey('quoteAuthorization', $wire);
	}

	/**
	 * Without an `interactionPolicy.canQuote`, Mastodon 4.5 treats a post as
	 * unquotable and offers no quote button at all.
	 */
	public function testALocalPublicPostSaysAnybodyMayQuoteIt(): void {
		$note = new Note();
		$note->setId('https://cloud.example.org/apps/social/@alice/1');
		$note->setLocal(true);
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$note->setAttributedTo('https://cloud.example.org/apps/social/@alice');

		$this->assertSame(
			['canQuote' => ['automaticApproval' => [ACore::CONTEXT_PUBLIC]]],
			$note->exportAsActivityPub()['interactionPolicy']
		);
	}

	public function testALocalFollowersOnlyPostSaysNobodyButTheAuthorMayQuoteIt(): void {
		$note = new Note();
		$note->setId('https://cloud.example.org/apps/social/@alice/1');
		$note->setLocal(true);
		$note->setVisibility(Stream::TYPE_FOLLOWERS);
		$note->setAttributedTo('https://cloud.example.org/apps/social/@alice');

		$this->assertSame(
			['canQuote' => ['automaticApproval' => ['https://cloud.example.org/apps/social/@alice']]],
			$note->exportAsActivityPub()['interactionPolicy']
		);
	}

	/**
	 * The whole inbound path over a document shaped the way Mastodon 4.5 sends
	 * one: through the registry, into a Note, with the quote on it.
	 */
	public function testAMastodonQuotePostArrivesAsAQuote(): void {
		$create = AP::instance()->getItemFromData([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => self::QUOTING . '/activity',
			'type' => 'Create',
			'actor' => 'https://mastodon.social/users/alice',
			'to' => [ACore::CONTEXT_PUBLIC],
			'object' => [
				'id' => self::QUOTING,
				'type' => 'Note',
				'attributedTo' => 'https://mastodon.social/users/alice',
				'content' => '<p>this is worth reading</p>',
				'to' => [ACore::CONTEXT_PUBLIC],
				'quote' => self::QUOTED,
				'quoteUrl' => self::QUOTED,
				'_misskey_quote' => self::QUOTED,
				'quoteAuthorization' => 'https://mastodon.social/users/bob/approvals/1',
				'tag' => [],
			],
		]);

		$note = $create->getObject();

		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame(self::QUOTED, $note->getQuote());
		$this->assertSame('https://mastodon.social/users/bob/approvals/1', $note->getQuoteAuthorization());
		// and the document it was built from is what a reload re-reads it from
		$this->assertSame(self::QUOTED, json_decode($note->getSource(), true)['quote']);
	}

	/** Somebody else's post: their server says who may quote it, not ours. */
	public function testARemotePostCarriesNoPolicyOfOurs(): void {
		$note = new Note();
		$note->setId(self::QUOTED);
		$note->setVisibility(Stream::TYPE_PUBLIC);

		$this->assertArrayNotHasKey('interactionPolicy', $note->exportAsActivityPub());
	}
}
