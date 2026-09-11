<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Activity;

use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use PHPUnit\Framework\TestCase;

/**
 * FEP-044f's QuoteRequest as it arrives from Mastodon 4.5 and as we send it.
 */
class QuoteRequestTest extends TestCase {
	private const REQUEST = 'https://mastodon.social/users/alice#quote_requests/1';
	private const QUOTED = 'https://cloud.example.org/apps/social/@bob/1';
	private const QUOTING = 'https://mastodon.social/users/alice/statuses/2';

	private function wire(): array {
		return [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'type' => 'QuoteRequest',
			'id' => self::REQUEST,
			'actor' => 'https://mastodon.social/users/alice',
			'object' => self::QUOTED,
			'instrument' => self::QUOTING,
			'to' => 'https://cloud.example.org/apps/social/@bob',
		];
	}

	public function testTheTypeIsTheOneFepDefines(): void {
		$this->assertSame('QuoteRequest', (new QuoteRequest())->getType());
		$this->assertSame('QuoteRequest', QuoteRequest::TYPE);
	}

	public function testImportReadsWhoWantsToQuoteWhat(): void {
		$request = new QuoteRequest();
		$request->import($this->wire());

		$this->assertSame(self::REQUEST, $request->getId());
		$this->assertSame('https://mastodon.social/users/alice', $request->getActorId());
		// the post being quoted
		$this->assertSame(self::QUOTED, $request->getObjectId());
		// the post doing the quoting
		$this->assertSame(self::QUOTING, $request->getInstrument());
	}

	public function testARequestWithoutAnInstrumentNamesNoQuotingPost(): void {
		$wire = $this->wire();
		unset($wire['instrument']);

		$request = new QuoteRequest();
		$request->import($wire);

		$this->assertSame('', $request->getInstrument());
	}

	/**
	 * Without `instrument` the request says that somebody wants to quote a post
	 * but not which post would be doing the quoting — there would be nothing to
	 * approve.
	 */
	public function testTheQuotingPostIsOnTheWire(): void {
		$request = new QuoteRequest();
		$request->setId(self::REQUEST);
		$request->setActorId('https://mastodon.social/users/alice');
		$request->setObjectId(self::QUOTED);
		$request->setInstrument(self::QUOTING);

		$wire = $request->exportAsActivityPub();

		$this->assertSame('QuoteRequest', $wire['type']);
		$this->assertSame(self::QUOTED, $wire['object']);
		$this->assertSame(self::QUOTING, $wire['instrument']);
	}

	public function testNoInstrumentKeyWithoutAQuotingPost(): void {
		$request = new QuoteRequest();
		$request->setId(self::REQUEST);
		$request->setObjectId(self::QUOTED);

		$this->assertArrayNotHasKey('instrument', $request->exportAsActivityPub());
	}
}
