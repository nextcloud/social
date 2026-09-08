<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Listeners;

use OCA\Social\Listeners\ProfileSectionListener;
use OCP\Accounts\UserUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class ProfileSectionListenerTest extends TestCase {
	public function testIsAnEventListener(): void {
		$this->assertInstanceOf(IEventListener::class, new ProfileSectionListener());
	}

	/** @return iterable<string, array{Event}> */
	public function unrelatedEvents(): iterable {
		yield 'generic event' => [new Event()];
		yield 'account update' => [new UserUpdatedEvent($this->createMock(IUser::class), [])];
	}

	/**
	 * Registering the profile script needs the server's script registry, so an
	 * unrelated event must return before reaching it.
	 *
	 * @dataProvider unrelatedEvents
	 */
	public function testUnrelatedEventsAreIgnored(Event $event): void {
		(new ProfileSectionListener())->handle($event);

		$this->addToAssertionCount(1);
	}
}
