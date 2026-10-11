<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ChannelController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Channel;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ChannelService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `/api/v1/channels`: the video channels an account owns.
 *
 * Session routes the server already closes to anonymous callers; the
 * controller's own check is the second line, and the owner is always the
 * caller — no route takes an account to act for.
 */
#[AllowMockObjectsWithoutExpectations]
class ChannelControllerTest extends TestCase {
	private ChannelService&MockObject $channels;
	private AccountService&MockObject $accountService;

	protected function setUp(): void {
		$this->channels = $this->createMock(ChannelService::class);
		$this->accountService = $this->createMock(AccountService::class);
	}

	/** @return array<string, array{callable(ChannelController): DataResponse}> */
	public static function routes(): array {
		return [
			'list' => [static fn (ChannelController $c): DataResponse => $c->index()],
			'create' => [static fn (ChannelController $c): DataResponse => $c->create('cooking', 'Cooking')],
			'update' => [static fn (ChannelController $c): DataResponse => $c->update(5, 'Baking')],
		];
	}

	#[DataProvider('routes')]
	public function testWithoutAUserNoChannelIsReadOrChanged(callable $call): void {
		$this->channels->expects($this->never())->method($this->anything());
		$this->accountService->expects($this->never())->method($this->anything());

		$response = $call($this->controller(null));

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testAChannelIsUpdatedOnBehalfOfTheCallerOnly(): void {
		$actor = new Person();
		$actor->setId('https://cloud.example/users/alice');
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($actor);
		$channel = (new Channel())->setId(5)->setName('Baking');
		$this->channels->expects($this->once())->method('update')
			->with($actor, 5, 'Baking', '')
			->willReturn($channel);

		$response = $this->controller('alice')->update(5, 'Baking');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['channels' => [$channel]], $response->getData());
	}

	private function controller(?string $userId): ChannelController {
		return new ChannelController(
			$this->createStub(IRequest::class), $userId, $this->channels, $this->accountService, new NullLogger(),
		);
	}
}
