<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Actor;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\Actor\Application;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Organization;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Actor\Service;
use OCA\Social\Tests\Model\TActivityPubMocks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../TActivityPubMocks.php';

/**
 * Service, Group, Organization and Application are Persons with another type.
 */
class ActorSubtypesTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installActivityPub();
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	public static function subtypeProvider(): array {
		return [
			'Service' => [Service::class, 'Service'],
			'Group' => [Group::class, 'Group'],
			'Organization' => [Organization::class, 'Organization'],
			'Application' => [Application::class, 'Application'],
		];
	}

	#[DataProvider('subtypeProvider')]
	public function testIsAPersonWithItsOwnTypeConstant(string $class, string $type): void {
		$actor = new $class();

		$this->assertInstanceOf(Person::class, $actor);
		$this->assertSame($type, $class::TYPE);
	}

	#[DataProvider('subtypeProvider')]
	public function testImportKeepsTheTypeAndReadsActorFields(string $class, string $type): void {
		/** @var Person $actor */
		$actor = new $class();

		$actor->import([
			'id' => 'https://bots.example/actor',
			'type' => $type,
			'preferredUsername' => 'relay',
			'inbox' => 'https://bots.example/inbox',
			'publicKey' => ['publicKeyPem' => 'PEM'],
		]);

		$this->assertSame($type, $actor->getType());
		$this->assertSame('relay', $actor->getPreferredUsername());
		$this->assertSame('https://bots.example/inbox', $actor->getInbox());
		$this->assertSame('PEM', $actor->getPublicKey());
		$this->assertSame($type, $actor->exportAsActivityPub()['type']);
	}

	#[DataProvider('subtypeProvider')]
	public function testAnAutomatedActorIsExportedAsABot(string $class, string $type): void {
		/** @var Person $actor */
		$actor = new $class();
		$actor->import(['id' => 'https://bots.example/actor', 'type' => $type]);

		// what a client reads to put the "bot" label on an account; nothing
		// else on the wire states it, and `bot` was never imported at all
		$this->assertSame(
			in_array($type, ['Service', 'Application'], true),
			$actor->isBot()
		);
		$this->assertSame($actor->isBot(), $actor->exportAsLocal()['bot']);
	}

	#[DataProvider('subtypeProvider')]
	public function testTheCachedRowRemembersThatAnAccountIsAutomated(string $class, string $type): void {
		$actor = new Person();
		$actor->importFromDatabase([
			'id' => 'https://bots.example/actor',
			'type' => $type,
			'account' => 'relay@bots.example',
			'preferred_username' => 'relay',
		]);

		$this->assertSame(in_array($type, ['Service', 'Application'], true), $actor->isBot());
	}
}
