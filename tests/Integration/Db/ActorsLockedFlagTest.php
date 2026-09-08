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
 * The locked (manuallyApprovesFollowers) flag against the real actor table:
 * created rows persist it, updateLocked() flips it in place, and reads carry
 * it back into the model. The unit suite covers the import/export mapping;
 * only this proves the column actually round-trips through the database.
 */
class ActorsLockedFlagTest extends TestCase {
	private const USERNAME = 'lockedflag-itest';

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

	private function createActor(bool $locked): Person {
		$actor = new Person();
		$actor->setPreferredUsername(self::USERNAME);
		$actor->setUserId(self::USERNAME);
		$actor->setLocked($locked);
		Server::get(SignatureService::class)->generateKeys($actor);
		$this->actorsRequest->create($actor);

		return $actor;
	}

	public function testAFreshActorIsUnlockedByDefault(): void {
		$this->createActor(false);

		$this->assertFalse($this->actorsRequest->getFromUsername(self::USERNAME)->isLocked());
	}

	public function testALockedActorRoundTripsThroughCreate(): void {
		$this->createActor(true);

		$this->assertTrue($this->actorsRequest->getFromUsername(self::USERNAME)->isLocked());
	}

	public function testUpdateLockedFlipsTheFlagInPlace(): void {
		$actor = $this->createActor(false);
		$stored = $this->actorsRequest->getFromUsername(self::USERNAME);

		$stored->setLocked(true);
		$this->actorsRequest->updateLocked($stored);
		$this->assertTrue($this->actorsRequest->getFromUsername(self::USERNAME)->isLocked());

		$stored->setLocked(false);
		$this->actorsRequest->updateLocked($stored);
		$this->assertFalse($this->actorsRequest->getFromUsername(self::USERNAME)->isLocked());

		// the flag never bleeds into other columns
		$this->assertSame(
			$actor->getPrivateKey(),
			$this->actorsRequest->getFromUsername(self::USERNAME)->getPrivateKey()
		);
	}
}
