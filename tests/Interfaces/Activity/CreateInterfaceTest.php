<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Interfaces\Activity\CreateInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\Activity\Create;

require_once __DIR__ . '/DispatchingActivityTestCase.php';

class CreateInterfaceTest extends DispatchingActivityTestCase {
	protected function createHandler(): IActivityPubInterface {
		return new CreateInterface();
	}

	protected function activityType(): string {
		return Create::TYPE;
	}

	public function testCreateOfANoteGoesToTheNoteInterface(): void {
		$note = $this->note(self::REMOTE_URL . '/notes/1', self::REMOTE_URL . '/users/bob');
		$create = $this->incoming(Create::TYPE, self::REMOTE_URL . '/notes/1/activity', self::REMOTE_URL . '/users/bob', $note);

		$this->noteInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($create), $this->identicalTo($note));

		$this->createHandler()->processIncomingRequest($create);
	}
}
