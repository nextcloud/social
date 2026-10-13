<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Helper;

use OC\AppFramework\DependencyInjection\DIContainer;
use OCA\Social\Db\StoriesRequest;
use OCA\Social\Model\Client\Story;
use OCA\Social\Tests\Helper\FrozenClock;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A frozen clock ends with the test that froze it.
 *
 * The server builds the app's services lazily: one handed out before a test
 * froze the clock takes its clock when it is first used. Used during the
 * freeze, it kept that test's clock for the rest of the run, and which test
 * met it next depended on the order — in random order on PostgreSQL a
 * signature made a second earlier was judged against a day long gone and
 * refused.
 */
class FreezesTheClockTest extends TestCase {
	use FreezesTheClock;

	private const OWNER = 'https://itest.example/users/frozen-clock-owner';
	private const FROZEN_AT = '2026-01-01T01:05:00Z';

	private ?DIContainer $realBootContainer = null;

	protected function setUp(): void {
		parent::setUp();
		$this->realBootContainer = \OC::$server->getRegisteredAppContainer('social');
	}

	protected function tearDown(): void {
		$this->thawClock();
		if ($this->realBootContainer !== null) {
			\OC::$server->registerAppContainer('social', $this->realBootContainer);
		}
		Server::get(StoriesRequest::class)->deleteRelatedId(self::OWNER);
		parent::tearDown();
	}

	public function testAServiceFirstUsedWhileFrozenIsOnTheRealClockOnceThawed(): void {
		// a boot container that has built nothing yet, as on the first test of a run
		new DIContainer('social');
		$stories = Server::get(StoriesRequest::class);

		$clock = $this->freezeClock(FrozenClock::at(self::FROZEN_AT));
		$this->assertSame($clock->getTime() + Story::LIFETIME, $this->story($stories)->getExpiresAt(), 'stamped on the frozen clock');
		$this->thawClock();

		$this->assertEqualsWithDelta(time() + Story::LIFETIME, $this->story($stories)->getExpiresAt(), 5);
		$this->assertEqualsWithDelta(time() + Story::LIFETIME, $this->story(Server::get(StoriesRequest::class))->getExpiresAt(), 5);
	}

	private function story(StoriesRequest $stories): Story {
		return $stories->save((new Story())
			->setOwnerId(self::OWNER)
			->setDocumentId('https://itest.example/media/' . bin2hex(random_bytes(4)))
			->setDuration(Story::DEFAULT_DURATION)
			->setSourceId('https://itest.example/stories/' . bin2hex(random_bytes(4)))
			->setLocal(true));
	}
}
