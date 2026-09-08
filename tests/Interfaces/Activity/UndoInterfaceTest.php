<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Interfaces\Activity\UndoInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;

require_once __DIR__ . '/DispatchingActivityTestCase.php';

class UndoInterfaceTest extends DispatchingActivityTestCase {
	protected function createHandler(): IActivityPubInterface {
		return new UndoInterface();
	}

	protected function activityType(): string {
		return Undo::TYPE;
	}

	public function testUndoOfALikeGoesToTheLikeInterface(): void {
		$like = new Like();
		$like->setId(self::REMOTE_URL . '/likes/1');
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', self::REMOTE_URL . '/users/bob', $like);

		$this->likeInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($undo), $this->identicalTo($like));
		$this->followInterface->expects($this->never())->method('activity');
		$this->announceInterface->expects($this->never())->method('activity');

		$this->createHandler()->processIncomingRequest($undo);
	}

	public function testUndoOfABoostGoesToTheAnnounceInterface(): void {
		$announce = new Announce();
		$announce->setId(self::REMOTE_URL . '/announces/1');
		$undo = $this->incoming(Undo::TYPE, self::REMOTE_URL . '/undo/1', self::REMOTE_URL . '/users/bob', $announce);

		$this->announceInterface->expects($this->once())
			->method('activity')
			->with($this->identicalTo($undo), $this->identicalTo($announce));
		$this->likeInterface->expects($this->never())->method('activity');

		$this->createHandler()->processIncomingRequest($undo);
	}
}
