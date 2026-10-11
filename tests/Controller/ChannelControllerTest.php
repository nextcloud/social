<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ChannelController;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Channel;
use OCA\Social\Service\ChannelService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `/api/v1/channels`: the video channels an account owns.
 *
 * Client-API routes: the list asks a token for `read:accounts`, making and
 * changing one for `write:accounts`, and the web page's session reaches all
 * three with its CSRF token. The owner is always the caller — no route takes
 * an account to act for.
 */
#[AllowMockObjectsWithoutExpectations]
class ChannelControllerTest extends TestCase {
	use ClientApiCallers;

	private ChannelService&MockObject $channels;

	protected function setUp(): void {
		$this->setUpCallers();
		$this->channels = $this->createMock(ChannelService::class);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/** @return array<string, array{callable(ChannelController): DataResponse, string[], string[]}> route, a scope that serves it, one that does not */
	public static function routes(): array {
		return [
			'list' => [static fn (ChannelController $c): DataResponse => $c->index(), ['read:accounts'], ['read:statuses']],
			'create' => [static fn (ChannelController $c): DataResponse => $c->create('cooking', 'Cooking'), ['write:accounts'], ['read']],
			'update' => [static fn (ChannelController $c): DataResponse => $c->update(5, 'Baking'), ['write:accounts'], ['write:statuses']],
		];
	}

	/**
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testWithoutAUserNoChannelIsReadOrChanged(callable $call, array $granted, array $other): void {
		$this->channels->expects($this->never())->method($this->anything());

		$response = $call($this->controller());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/**
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testATokenGrantedTheRouteScopeIsServed(callable $call, array $granted, array $other): void {
		$this->asToken($granted);
		$this->channels->method('forOwner')->willReturn([]);
		$this->channels->method('create')->willReturn(new Channel());
		$this->channels->method('update')->willReturn(new Channel());

		$this->assertSame(Http::STATUS_OK, $call($this->controller())->getStatus());
	}

	/**
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testATokenWithoutTheRouteScopeIsRefused(callable $call, array $granted, array $other): void {
		$this->asToken($other);
		$this->channels->expects($this->never())->method($this->anything());

		$this->assertSame(Http::STATUS_FORBIDDEN, $call($this->controller())->getStatus());
	}

	public function testAChannelIsUpdatedOnBehalfOfTheCallerOnly(): void {
		$this->asSession('alice');
		$channel = (new Channel())->setId(5)->setName('Baking');
		$this->channels->expects($this->once())->method('update')
			->with($this->callback(static fn (Person $owner): bool => $owner->getUserId() === 'alice'), 5, 'Baking', '')
			->willReturn($channel);

		$response = $this->controller()->update(5, 'Baking');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['channels' => [$channel]], $response->getData());
	}

	/** A handle that is taken or is not one is the caller's to fix, and the message says which. */
	public function testARefusedHandleIsUnprocessableWithItsReason(): void {
		$this->asToken(['write']);
		$this->channels->method('create')->willThrowException(new InvalidResourceException('that handle is taken'));

		$response = $this->controller()->create('cooking');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'that handle is taken'], $response->getData());
	}

	private function controller(): ChannelController {
		return new ChannelController(
			$this->request, $this->userSession, new NullLogger(), $this->accountService, $this->clientService, $this->channels,
		);
	}
}
