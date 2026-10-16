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
 * `ActorsRequest::userIdsOf()` against the real database: the Nextcloud user a
 * username belongs to, which the username need not be, and nobody's once the
 * account is deleted.
 */
class ActorUserIdTest extends TestCase {
	private const USERNAME = 'uid-itest-al';
	private const USER_ID = 'uid-itest-alice';

	private ActorsRequest $actorsRequest;

	protected function setUp(): void {
		parent::setUp();
		$this->actorsRequest = Server::get(ActorsRequest::class);
		$this->actorsRequest->delete(self::USERNAME);

		$actor = new Person();
		$actor->setPreferredUsername(self::USERNAME);
		$actor->setUserId(self::USER_ID);
		Server::get(SignatureService::class)->generateKeys($actor);
		$this->actorsRequest->create($actor);
	}

	protected function tearDown(): void {
		$this->actorsRequest->delete(self::USERNAME);
		parent::tearDown();
	}

	public function testAUsernameNamesTheNextcloudUserItBelongsTo(): void {
		$this->assertSame(
			[self::USERNAME => self::USER_ID],
			$this->actorsRequest->userIdsOf([self::USERNAME, 'uid-itest-nobody', self::USERNAME])
		);
		$this->assertSame([], $this->actorsRequest->userIdsOf([]));
	}

	/** A page names more authors than one `IN ()` is given. */
	public function testManyUsernamesAreAskedInChunks(): void {
		$usernames = array_map(static fn (int $i): string => 'uid-itest-nobody-' . $i, range(1, 1200));
		$usernames[] = self::USERNAME;

		$this->assertSame([self::USERNAME => self::USER_ID], $this->actorsRequest->userIdsOf($usernames));
	}

	public function testADeletedAccountBelongsToNobody(): void {
		$this->actorsRequest->setAsDeleted(self::USERNAME);

		$this->assertSame([], $this->actorsRequest->userIdsOf([self::USERNAME]));
	}
}
