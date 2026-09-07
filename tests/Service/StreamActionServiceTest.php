<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Exceptions\StreamActionDoesNotExistException;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\StreamActionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StreamActionServiceTest extends TestCase {
	private const ACTOR = 'https://cloud.example.com/apps/social/@alice';
	private const STREAM = 'https://remote.example/notes/1';

	private StreamActionsRequest|MockObject $streamActionsRequest;
	private StreamActionService $service;

	protected function setUp(): void {
		$this->streamActionsRequest = $this->createMock(StreamActionsRequest::class);
		$this->service = new StreamActionService($this->streamActionsRequest, $this->createMock(MiscService::class));
	}

	public function testSetActionUpdatesAnExistingRow(): void {
		$existing = new StreamAction(self::ACTOR, self::STREAM);
		$existing->setId(12);
		$this->streamActionsRequest->method('getAction')
			->with(self::ACTOR, self::STREAM)
			->willReturn($existing);
		$this->streamActionsRequest->expects($this->once())
			->method('update')
			->with($this->identicalTo($existing))
			->willReturn(1);
		$this->streamActionsRequest->expects($this->never())->method('create');

		$this->service->setAction(self::ACTOR, self::STREAM, 'note', 'hello');

		$this->assertSame('hello', $existing->getValue('note'));
		$this->assertSame([], $existing->getAffected(), 'unknown keys are not tracked as affected');
	}

	public function testSetActionCreatesTheRowWhenNoneExists(): void {
		$this->streamActionsRequest->method('getAction')
			->willThrowException(new StreamActionDoesNotExistException());
		$this->streamActionsRequest->method('update')->willReturn(0);
		$created = null;
		$this->streamActionsRequest->expects($this->once())
			->method('create')
			->willReturnCallback(function (StreamAction $action) use (&$created) {
				$created = $action;
			});

		$this->service->setAction(self::ACTOR, self::STREAM, StreamAction::LIKED, '1');

		$this->assertSame(self::ACTOR, $created->getActorId());
		$this->assertSame(self::STREAM, $created->getStreamId());
		$this->assertSame('1', $created->getValue(StreamAction::LIKED));
		$this->assertSame([StreamAction::LIKED], $created->getAffected());
	}

	public function testSetActionCreatesWhenTheUpdateTouchedNoRow(): void {
		$existing = new StreamAction(self::ACTOR, self::STREAM);
		$this->streamActionsRequest->method('getAction')->willReturn($existing);
		$this->streamActionsRequest->method('update')->willReturn(0);
		$this->streamActionsRequest->expects($this->once())
			->method('create')
			->with($this->identicalTo($existing));

		$this->service->setAction(self::ACTOR, self::STREAM, StreamAction::REPLIED, 'yes');
	}

	public function testSetActionIntStoresAnInteger(): void {
		$this->streamActionsRequest->method('getAction')
			->willThrowException(new StreamActionDoesNotExistException());
		$this->streamActionsRequest->method('update')->willReturn(1);
		$this->streamActionsRequest->expects($this->once())
			->method('update')
			->with($this->callback(function (StreamAction $action) {
				$this->assertSame(3, $action->getValueInt('count'));

				return true;
			}));

		$this->service->setActionInt(self::ACTOR, self::STREAM, 'count', 3);
	}

	public function testSetActionBoolTracksAcceptedKeysAsAffected(): void {
		$this->streamActionsRequest->method('getAction')
			->willThrowException(new StreamActionDoesNotExistException());
		$this->streamActionsRequest->expects($this->once())
			->method('update')
			->with($this->callback(function (StreamAction $action) {
				$this->assertTrue($action->getValueBool(StreamAction::BOOSTED));
				$this->assertSame([StreamAction::BOOSTED], $action->getAffected());

				return true;
			}))
			->willReturn(1);

		$this->service->setActionBool(self::ACTOR, self::STREAM, StreamAction::BOOSTED, true);
	}
}
