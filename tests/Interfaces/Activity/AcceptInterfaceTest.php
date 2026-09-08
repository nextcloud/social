<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Interfaces\Activity\AcceptInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Object\Note;

require_once __DIR__ . '/DispatchingActivityTestCase.php';

class AcceptInterfaceTest extends DispatchingActivityTestCase {
	protected function createHandler(): IActivityPubInterface {
		return new AcceptInterface();
	}

	protected function activityType(): string {
		return Accept::TYPE;
	}

	public function testAcceptOfSomethingElseThanAFollowIsNotAFollowConfirmation(): void {
		$note = new Note();
		$note->setId(self::REMOTE_URL . '/notes/1');
		$accept = $this->incoming(Accept::TYPE, self::REMOTE_URL . '/accepts/1', self::REMOTE_URL . '/users/bob', $note);

		$this->followInterface->expects($this->never())->method('activity');
		$this->noteInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($accept), $this->identicalTo($note));

		$this->createHandler()->processIncomingRequest($accept);
	}
}
