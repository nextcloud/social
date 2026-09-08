<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Interfaces\Activity\AbstractActivityPubInterface;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Object\Note;
use PHPUnit\Framework\TestCase;

class AbstractActivityPubInterfaceTest extends TestCase {
	private AbstractActivityPubInterface $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new AbstractActivityPubInterface();
	}

	public function testGetItemIsNotSupportedByDefault(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItem(new Note());
	}

	public function testGetItemByIdIsNotSupportedByDefault(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->handler->getItemById('https://remote.example/notes/1');
	}

	public function testEveryOtherHookIsANoOp(): void {
		$this->expectNotToPerformAssertions();

		$note = new Note();
		$this->handler->processIncomingRequest($note);
		$this->handler->processResult($note);
		$this->handler->save($note);
		$this->handler->update($note);
		$this->handler->delete($note);
		$this->handler->event($note, 'updateCache');
		$this->handler->activity(new Create(), $note);
	}
}
