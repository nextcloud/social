<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\SignatureService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The bio against the real actor table.
 *
 * `summary` is a TEXT column, and TEXT is where this app has been caught
 * before: MySQL silently drops a DEFAULT from one, so a column that looked
 * fine everywhere else refused an insert on exactly one database. A bio is
 * also the longest free text a user can put in the actor row and the most
 * likely to carry an apostrophe, an emoji or a newline, none of which the unit
 * suite's mocked query builder can say anything about.
 *
 * `updateSummary()` writes one column; the rest of the row has to still be
 * there afterwards, which is the other half of what is asserted here.
 */
class ActorsSummaryTest extends TestCase {
	private const USERNAME = 'summary-itest';

	private ActorsRequest $actorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->actorsRequest = Server::get(ActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->actorsRequest->delete(self::USERNAME);
	}

	private function createActor(): Person {
		$actor = new Person();
		$actor->setPreferredUsername(self::USERNAME);
		$actor->setUserId(self::USERNAME);
		Server::get(SignatureService::class)->generateKeys($actor);
		$this->actorsRequest->create($actor);

		return $actor;
	}

	private function storeSummary(string $summary): Person {
		$stored = $this->actorsRequest->getFromUsername(self::USERNAME);
		$stored->setSummary($summary);
		$this->actorsRequest->updateSummary($stored);

		return $this->actorsRequest->getFromUsername(self::USERNAME);
	}

	public function testAFreshActorHasNoBio(): void {
		$this->createActor();

		$this->assertSame('', $this->actorsRequest->getFromUsername(self::USERNAME)->getSummary());
	}

	public function testABioRoundTripsThroughTheActorRow(): void {
		$this->createActor();

		$bio = "Maintainer of things.\nElsewhere: @someone@example.org";

		$this->assertSame($bio, $this->storeSummary($bio)->getSummary());
	}

	public function testWritingTheBioAgainReplacesIt(): void {
		$this->createActor();
		$this->storeSummary('First.');

		$this->assertSame('Second.', $this->storeSummary('Second.')->getSummary());
	}

	public function testABioCanBeCleared(): void {
		$this->createActor();
		$this->storeSummary('Something.');

		$this->assertSame('', $this->storeSummary('')->getSummary());
	}

	/**
	 * An apostrophe stays an apostrophe and an emoji survives: the column is
	 * utf8mb4 on MySQL, and a bio is the likeliest place in the row to prove
	 * it is not.
	 */
	public function testUnicodeAndQuotesSurviveTheTextColumn(): void {
		$this->createActor();

		$bio = "L'été 🌞 — “quoted”, ünicode, <not markup>";

		$this->assertSame($bio, $this->storeSummary($bio)->getSummary());
	}

	/**
	 * 500 characters is the cap the API applies before it gets here; TEXT holds
	 * far more, so what is stored has to come back whole rather than cut by the
	 * column.
	 */
	public function testABioAtTheApiLimitIsNotTruncatedByTheColumn(): void {
		$this->createActor();

		$bio = str_repeat('ä', 500);

		$read = $this->storeSummary($bio)->getSummary();
		$this->assertSame(500, mb_strlen($read, 'UTF-8'));
		$this->assertSame($bio, $read);
	}

	/** One column is written; the row is not rebuilt from an empty model. */
	public function testWritingTheBioLeavesTheRestOfTheActorAlone(): void {
		$created = $this->createActor();

		$this->storeSummary('A bio.');

		$after = $this->actorsRequest->getFromUsername(self::USERNAME);
		$this->assertSame(self::USERNAME, $after->getPreferredUsername());
		$this->assertSame($created->getUserId(), $after->getUserId());
		$this->assertNotSame('', $after->getPublicKey(), 'the key pair was lost writing a bio');
		$this->assertNotSame('', $after->getPrivateKey(), 'the key pair was lost writing a bio');
	}
}
