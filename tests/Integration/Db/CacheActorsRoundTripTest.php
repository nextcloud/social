<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The remote-actor cache against the real database: the save/read round trip of
 * the fields federation depends on — including the raw source document, which is
 * where alsoKnownAs survives a restart and what the Move guard reads from a
 * cached target.
 */
class CacheActorsRoundTripTest extends TestCase {
	private const ACTOR = 'https://remote.example/catest/users/erin';

	private CacheActorsRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		$this->request->deleteCacheById(self::ACTOR);
	}

	private function erin(): Person {
		$person = new Person();
		$person->setId(self::ACTOR)
			->setPreferredUsername('erin');
		$person->setAccount('erin@remote.example')
			->setName('Erin')
			->setInbox(self::ACTOR . '/inbox')
			->setOutbox(self::ACTOR . '/outbox')
			->setSharedInbox('https://remote.example/inbox')
			->setFollowers(self::ACTOR . '/followers')
			->setFollowing(self::ACTOR . '/following')
			->setPublicKey("-----BEGIN PUBLIC KEY-----\nMIIB-catest\n-----END PUBLIC KEY-----\n");
		$person->setSource(json_encode([
			'id' => self::ACTOR,
			'alsoKnownAs' => ['https://old.example/catest/users/erin'],
			'image' => ['url' => 'https://remote.example/catest/header.jpg'],
		], JSON_UNESCAPED_SLASHES));

		return $person;
	}

	public function testTheFederationFieldsRoundTrip(): void {
		$this->request->save($this->erin());

		$read = $this->request->getFromId(self::ACTOR);

		$this->assertSame('erin', $read->getPreferredUsername());
		$this->assertSame('erin@remote.example', $read->getAccount());
		$this->assertSame(self::ACTOR . '/inbox', $read->getInbox());
		$this->assertSame('https://remote.example/inbox', $read->getSharedInbox());
		$this->assertSame(self::ACTOR . '/followers', $read->getFollowers());
		$this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $read->getPublicKey());
		$this->assertFalse($read->isLocal());
	}

	public function testAlsoKnownAsAndHeaderSurviveThroughTheStoredSource(): void {
		$this->request->save($this->erin());

		$read = $this->request->getFromId(self::ACTOR);

		$this->assertSame(
			['https://old.example/catest/users/erin'],
			$read->getAlsoKnownAs(),
			'the Move guard must see the alias of a cached actor'
		);
		$this->assertSame('https://remote.example/catest/header.jpg', $read->getHeader());
	}

	public function testLookupByAccountAndDeletion(): void {
		$this->request->save($this->erin());

		$this->assertSame(self::ACTOR, $this->request->getFromAccount('erin@remote.example')->getId());

		$this->request->deleteCacheById(self::ACTOR);
		$this->expectException(CacheActorDoesNotExistException::class);
		$this->request->getFromAccount('erin@remote.example');
	}
}
