<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Internal;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Service\MiscService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\MockObject\MockObject;

require_once __DIR__ . '/../ActivityPubTestCase.php';

class SocialAppNotificationInterfaceTest extends ActivityPubTestCase {
	private const ID = self::REMOTE_URL . '/follows/1/notification';

	/** @var StreamRequest&MockObject */
	private $streamRequest;
	/** @var MiscService&MockObject */
	private $miscService;
	private SocialAppNotificationInterface $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->miscService = $this->createMock(MiscService::class);

		$this->handler = new SocialAppNotificationInterface($this->streamRequest, $this->miscService);
	}

	private function notification(): SocialAppNotification {
		$notification = new SocialAppNotification();
		$notification->setId(self::ID);
		$notification->setSubType(Follow::TYPE);
		$notification->setTo(self::LOCAL_URL . '/users/alice');
		$notification->setLocal(true);

		return $notification;
	}

	public function testNotificationWithoutAnIdIsNotStored(): void {
		$this->streamRequest->expects($this->never())->method('save');
		$this->miscService->expects($this->never())->method('log');

		$this->handler->save(new SocialAppNotification());
	}

	public function testSaveStampsThePublicationTimeAndStoresTheNotificationAsAStream(): void {
		$notification = $this->notification();

		$this->streamRequest->expects($this->once())->method('save')->with($this->identicalTo($notification));

		$this->handler->save($notification);

		$this->assertNotSame('', $notification->getPublished());
		$this->assertEqualsWithDelta(time(), $notification->getPublishedTime(), 5);
	}

	public function testUpdateRewritesTheNotificationAndItsRecipients(): void {
		$notification = $this->notification();

		$this->streamRequest->expects($this->once())->method('update')->with($this->identicalTo($notification), true);
		$this->streamRequest->expects($this->never())->method('save');

		$this->handler->update($notification);
	}

	public function testDeleteRemovesOnlyTheNotificationRow(): void {
		$this->streamRequest->expects($this->once())->method('deleteById')->with(self::ID, SocialAppNotification::TYPE);

		$this->handler->delete($this->notification());
	}
}
