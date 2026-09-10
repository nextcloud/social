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
	private const OTHER = 'https://remote.example/catest/users/frank';
	private const NO_INBOX = 'https://remote.example/catest/users/ghost';

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
		foreach ([self::ACTOR, self::OTHER, self::NO_INBOX] as $id) {
			$this->request->deleteCacheById($id);
		}
	}

	private function actor(string $id, string $sharedInbox): Person {
		$person = new Person();
		$person->setId($id)->setPreferredUsername(md5($id));
		$person->setAccount(md5($id) . '@remote.example')
			->setInbox($id . '/inbox')
			->setSharedInbox($sharedInbox);
		$this->request->save($person);

		return $person;
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

	public function testARefreshCorrectsTheStoredHandle(): void {
		$this->request->save($this->erin());

		// what happens after the instance address is corrected: the actor is
		// rebuilt with the right handle and written back over the old row
		$renamed = $this->erin();
		$renamed->setAccount('erin@cloud.example');
		$this->request->update($renamed);

		$this->assertSame('erin@cloud.example', $this->request->getFromId(self::ACTOR)->getAccount());
		$this->assertSame(self::ACTOR, $this->request->getFromAccount('erin@cloud.example')->getId());
	}

	public function testARefreshThatKnowsNoHandleLeavesTheStoredOneAlone(): void {
		$this->request->save($this->erin());

		// an incoming Update{Person} carries no handle — ActivityPub has no such
		// field — and must not be able to blank one that is already right
		$fromTheWire = $this->erin();
		$fromTheWire->setAccount('');
		$fromTheWire->setName('Erin Renamed');
		$this->request->update($fromTheWire);

		$read = $this->request->getFromId(self::ACTOR);
		$this->assertSame('erin@remote.example', $read->getAccount());
		$this->assertSame('Erin Renamed', $read->getName(), 'the rest of the refresh still applied');
	}

	public function testLookupByAccountAndDeletion(): void {
		$this->request->save($this->erin());

		$this->assertSame(self::ACTOR, $this->request->getFromAccount('erin@remote.example')->getId());

		$this->request->deleteCacheById(self::ACTOR);
		$this->expectException(CacheActorDoesNotExistException::class);
		$this->request->getFromAccount('erin@remote.example');
	}

	public function testAPageOfActorsIsResolvedInOneQuery(): void {
		// what a page of reports or of blocks needs: whatever is cached, keyed
		// by id, instead of a lookup and a federated fetch per row
		$this->request->save($this->erin());
		$this->actor(self::OTHER, 'https://remote.example/inbox');

		$found = $this->request->getFromIds([self::ACTOR, self::OTHER, 'https://gone.example/@nobody']);

		$keys = array_keys($found);
		sort($keys);
		$this->assertSame([self::ACTOR, self::OTHER], $keys);
		$this->assertSame('erin', $found[self::ACTOR]->getPreferredUsername());
	}

	public function testAnIdThatIsNotAUriAsksTheDatabaseNothing(): void {
		$this->assertSame([], $this->request->getFromIds(['', 'not-a-uri']));
	}

	public function testSharedInboxesSkipWhatCannotBeDeliveredTo(): void {
		$this->request->save($this->erin());
		$this->actor(self::NO_INBOX, '');

		$inboxes = $this->request->getSharedInboxes();

		// '' used to come back and became a delivery addressed to the host ''
		$this->assertNotContains('', $inboxes);
		$this->assertContains('https://remote.example/inbox', $inboxes);
	}
}
