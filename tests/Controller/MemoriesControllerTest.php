<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\MemoriesController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\MemoriesService;
use OCA\Social\Service\PlaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDateTimeZone;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `/api/v1/memories`: a person's own past posts and their weekly recap.
 *
 * Client-API routes: the reads ask a token for `read:statuses` and the switch
 * for `write:accounts`, and the web page's session reaches all three with its
 * CSRF token. Whoever calls, the memories read are the caller's own — there
 * is no account to name.
 */
#[AllowMockObjectsWithoutExpectations]
class MemoriesControllerTest extends TestCase {
	use ClientApiCallers;

	private MemoriesService&MockObject $memories;

	protected function setUp(): void {
		$this->setUpCallers();
		$this->memories = $this->createMock(MemoriesService::class);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/** @return array<string, array{callable(MemoriesController): DataResponse, string[], string[]}> route, a scope that serves it, one that does not */
	public static function routes(): array {
		return [
			'on this day' => [static fn (MemoriesController $c): DataResponse => $c->onThisDay(), ['read:statuses'], ['read:accounts']],
			'recap' => [static fn (MemoriesController $c): DataResponse => $c->recap(), ['read:statuses'], ['write:accounts']],
			'switch the recap' => [static fn (MemoriesController $c): DataResponse => $c->setRecap(true), ['write:accounts'], ['read', 'write:statuses']],
		];
	}

	/**
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testWithoutAUserNothingIsReadOrChanged(callable $call, array $granted, array $other): void {
		$this->memories->expects($this->never())->method($this->anything());

		$response = $call($this->controller());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/** A session without its CSRF token is nobody: a link on another site cannot read or switch these. */
	/**
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testASessionWithoutItsCsrfTokenIsNobody(callable $call, array $granted, array $other): void {
		$this->asSession('alice', false);
		$this->memories->expects($this->never())->method($this->anything());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $call($this->controller())->getStatus());
	}

	/**
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testATokenGrantedTheRouteScopeIsServed(callable $call, array $granted, array $other): void {
		$this->asToken($granted);
		$this->memories->method('onThisDay')->willReturn([]);
		$this->memories->method('recap')->willReturn(['enabled' => false]);

		$this->assertSame(Http::STATUS_OK, $call($this->controller())->getStatus());
	}

	/**
	 * @param string[] $granted
	 * @param string[] $other
	 */
	#[DataProvider('routes')]
	public function testATokenWithoutTheRouteScopeIsRefused(callable $call, array $granted, array $other): void {
		$this->asToken($other);
		$this->memories->expects($this->never())->method($this->anything());

		$this->assertSame(Http::STATUS_FORBIDDEN, $call($this->controller())->getStatus());
	}

	public function testTheRecapIsReadForTheCallerOfTheToken(): void {
		$this->asToken(['read'], 'bob');
		$this->memories->expects($this->once())->method('recap')
			->with($this->callback(static fn (Person $actor): bool => $actor->getUserId() === 'bob'), 'bob')
			->willReturn(['posts' => 3]);

		$response = $this->controller()->recap();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['posts' => 3], $response->getData());
	}

	public function testTheRecapIsReadForTheSignedInUser(): void {
		$this->asSession('alice');
		$this->memories->expects($this->once())->method('recap')
			->with($this->callback(static fn (Person $actor): bool => $actor->getUserId() === 'alice'), 'alice')
			->willReturn(['posts' => 3]);

		$response = $this->controller()->recap();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['posts' => 3], $response->getData());
	}

	public function testSwitchingTheRecapChangesOnlyTheCallersSetting(): void {
		$this->asToken(['write:accounts'], 'bob');
		$this->memories->expects($this->once())->method('setRecapEnabled')->with('bob', false);

		$response = $this->controller()->setRecap(false);

		$this->assertSame(['enabled' => false], $response->getData());
	}

	private function controller(): MemoriesController {
		return new MemoriesController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->clientService,
			$this->memories,
			$this->createStub(LinkPreviewService::class),
			$this->createStub(PlaceService::class),
			$this->createStub(IDateTimeZone::class),
		);
	}
}
