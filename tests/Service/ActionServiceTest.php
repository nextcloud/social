<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ActionServiceTest extends TestCase {
	private const POST_ID = 'https://remote.example/notes/42';

	private StreamService|MockObject $streamService;
	private BoostService|MockObject $boostService;
	private LikeService|MockObject $likeService;
	private ActionService $service;
	private Person $actor;
	private Note $post;

	protected function setUp(): void {
		$this->streamService = $this->createMock(StreamService::class);
		$this->boostService = $this->createMock(BoostService::class);
		$this->likeService = $this->createMock(LikeService::class);
		$this->service = new ActionService(
			$this->streamService,
			$this->boostService,
			$this->likeService,
			$this->createMock(StreamActionService::class),
		);

		$this->actor = new Person();
		$this->actor->setId('https://cloud.example.com/apps/social/@alice');
		$this->post = new Note();
		$this->post->setId(self::POST_ID);
		$this->post->setNid(42);
	}

	public function testUnknownActionIsRejectedBeforeLoadingThePost(): void {
		$this->streamService->expects($this->never())->method('getStreamByNid');

		$this->expectException(InvalidActionException::class);
		$this->service->action($this->actor, 42, 'explode');
	}

	public function testFavouriteCreatesALike(): void {
		$this->streamService->expects($this->once())->method('getStreamByNid')->with(42)->willReturn($this->post);
		$this->likeService->expects($this->once())
			->method('create')
			->with($this->identicalTo($this->actor), self::POST_ID);
		$this->likeService->expects($this->never())->method('delete');
		$this->boostService->expects($this->never())->method('create');

		$this->assertNull($this->service->action($this->actor, 42, 'favourite'));
	}

	public function testUnfavouriteDeletesTheLike(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post);
		$this->likeService->expects($this->once())
			->method('delete')
			->with($this->identicalTo($this->actor), self::POST_ID);
		$this->likeService->expects($this->never())->method('create');

		$this->assertNull($this->service->action($this->actor, 42, 'unfavourite'));
	}

	public function testReblogCreatesABoost(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post);
		$this->boostService->expects($this->once())
			->method('create')
			->with($this->identicalTo($this->actor), self::POST_ID);
		$this->likeService->expects($this->never())->method('create');

		$this->assertNull($this->service->action($this->actor, 42, 'reblog'));
	}

	public function testUnreblogDeletesTheBoost(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post);
		$this->boostService->expects($this->once())
			->method('delete')
			->with($this->identicalTo($this->actor), self::POST_ID);
		$this->boostService->expects($this->never())->method('create');

		$this->assertNull($this->service->action($this->actor, 42, 'unreblog'));
	}

	public function testTranslateReturnsThePostItself(): void {
		$this->streamService->method('getStreamByNid')->with(42)->willReturn($this->post);

		$this->assertSame($this->post, $this->service->action($this->actor, 42, 'translate'));
	}

	/** @return array<string, array{string}> */
	public function noopActionProvider(): array {
		return [
			'bookmark' => ['bookmark'],
			'unbookmark' => ['unbookmark'],
			'mute' => ['mute'],
			'unmute' => ['unmute'],
			'pin' => ['pin'],
			'unpin' => ['unpin'],
		];
	}

	/** @dataProvider noopActionProvider */
	public function testKnownButUnimplementedActionsAreAcceptedSilently(string $action): void {
		$this->streamService->expects($this->once())->method('getStreamByNid')->willReturn($this->post);
		$this->likeService->expects($this->never())->method($this->anything());
		$this->boostService->expects($this->never())->method($this->anything());

		$this->assertNull($this->service->action($this->actor, 42, $action));
	}
}
