<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\MemoriesController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\MemoriesService;
use OCA\Social\Service\PlaceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IDateTimeZone;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `/api/v1/memories`: a person's own past posts and their weekly recap.
 *
 * The routes are session routes, so the server refuses an anonymous caller
 * before they run. The controller still checks for a user itself, because a
 * route that loses its attributes or gains `#[PublicPage]` would otherwise
 * read memories for a null account; these tests pin that second line.
 */
#[AllowMockObjectsWithoutExpectations]
class MemoriesControllerTest extends TestCase {
	private MemoriesService&MockObject $memories;
	private AccountService&MockObject $accountService;

	protected function setUp(): void {
		$this->memories = $this->createMock(MemoriesService::class);
		$this->accountService = $this->createMock(AccountService::class);
	}

	/** @return array<string, array{callable(MemoriesController): DataResponse}> */
	public static function routes(): array {
		return [
			'on this day' => [static fn (MemoriesController $c): DataResponse => $c->onThisDay()],
			'recap' => [static fn (MemoriesController $c): DataResponse => $c->recap()],
			'switch the recap' => [static fn (MemoriesController $c): DataResponse => $c->setRecap(true)],
		];
	}

	#[DataProvider('routes')]
	public function testWithoutAUserNothingIsReadOrChanged(callable $call): void {
		$this->memories->expects($this->never())->method($this->anything());
		$this->accountService->expects($this->never())->method($this->anything());

		$response = $call($this->controller(null));

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testTheRecapIsReadForTheSignedInUser(): void {
		$actor = new Person();
		$actor->setId('https://cloud.example/users/alice');
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($actor);
		$this->memories->expects($this->once())->method('recap')->with($actor, 'alice')->willReturn(['posts' => 3]);

		$response = $this->controller('alice')->recap();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['posts' => 3], $response->getData());
	}

	public function testSwitchingTheRecapChangesOnlyTheCallersSetting(): void {
		$this->memories->expects($this->once())->method('setRecapEnabled')->with('alice', false);

		$response = $this->controller('alice')->setRecap(false);

		$this->assertSame(['enabled' => false], $response->getData());
	}

	private function controller(?string $userId): MemoriesController {
		return new MemoriesController(
			$this->createStub(IRequest::class),
			$userId,
			$this->accountService,
			$this->memories,
			$this->createStub(LinkPreviewService::class),
			$this->createStub(PlaceService::class),
			$this->createStub(IDateTimeZone::class),
			new NullLogger(),
		);
	}
}
