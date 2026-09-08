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
 * Profile metadata fields against the real actor table: a fresh row has none,
 * updateFields() stores and replaces them, and unicode survives the JSON
 * column on every supported database. The unit suite covers the import/export
 * mapping; only this proves the column round-trips.
 */
class ActorsProfileFieldsTest extends TestCase {
	private const USERNAME = 'profilefields-itest';

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

	private function storeFields(array $fields): void {
		$stored = $this->actorsRequest->getFromUsername(self::USERNAME);
		$stored->setFields($fields);
		$this->actorsRequest->updateFields($stored);
	}

	public function testAFreshActorHasNoFields(): void {
		$this->createActor();

		$this->assertSame([], $this->actorsRequest->getFromUsername(self::USERNAME)->getFields());
	}

	public function testFieldsRoundTripThroughTheActorRow(): void {
		$actor = $this->createActor();
		$this->storeFields([
			['name' => 'Website', 'value' => 'https://example.org'],
			['name' => 'Pronouns', 'value' => 'they/them'],
		]);

		$stored = $this->actorsRequest->getFromUsername(self::USERNAME);
		$this->assertSame([
			['name' => 'Website', 'value' => 'https://example.org'],
			['name' => 'Pronouns', 'value' => 'they/them'],
		], $stored->getFields());

		// writing fields never bleeds into other columns
		$this->assertSame($actor->getPrivateKey(), $stored->getPrivateKey());
	}

	public function testStoringFieldsReplacesTheWholeSet(): void {
		$this->createActor();
		$this->storeFields([['name' => 'Website', 'value' => 'https://example.org']]);
		$this->storeFields([['name' => 'Pronouns', 'value' => 'they/them']]);

		$this->assertSame(
			[['name' => 'Pronouns', 'value' => 'they/them']],
			$this->actorsRequest->getFromUsername(self::USERNAME)->getFields()
		);
	}

	public function testClearingTheFieldsLeavesAnEmptySet(): void {
		$this->createActor();
		$this->storeFields([['name' => 'Website', 'value' => 'https://example.org']]);
		$this->storeFields([]);

		$this->assertSame([], $this->actorsRequest->getFromUsername(self::USERNAME)->getFields());
	}

	public function testUnicodeAndQuotesSurviveTheJsonColumn(): void {
		$this->createActor();
		$this->storeFields([['name' => 'Wohnort 🏠', 'value' => 'Stuttgart "Süd" & Co']]);

		$this->assertSame(
			[['name' => 'Wohnort 🏠', 'value' => 'Stuttgart "Süd" & Co']],
			$this->actorsRequest->getFromUsername(self::USERNAME)->getFields()
		);
	}
}
