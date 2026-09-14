<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\SocialPubController;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SocialPubControllerTest extends TestCase {
	/** @var IInitialState&MockObject */
	private $initialState;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	private array $states = [];

	protected function setUp(): void {
		$this->initialState = $this->createMock(IInitialState::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);

		// `PublicTemplateResponse` reaches the container for this one itself
		\OC::$server->register(
			IInitialStateService::class, $this->createMock(IInitialStateService::class)
		);

		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $data): void {
				$this->states['social'][$key] = $data;
			});
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function controller(): SocialPubController {
		return new SocialPubController(
			$this->initialState,
			$this->createMock(IRequest::class),
			$this->createMock(IL10N::class),
			$this->cacheActorService
		);
	}

	/** @return iterable<string, array{string}> */
	public static function publicPages(): iterable {
		yield 'actor' => ['actor'];
		yield 'followers' => ['followers'];
		yield 'following' => ['following'];
	}

	/**
	 * These are the pages a browser gets for a profile URL; the account is
	 * named in the title and the frontend is told nobody is signed in.
	 */
	#[DataProvider('publicPages')]
	public function testPublicPagesRenderTheProfileForAVisitor(string $page): void {
		$actor = $this->createMock(Person::class);
		$actor->method('getName')->willReturn('Alice Wonder');
		$actor->method('getPreferredUsername')->willReturn('alice');
		$this->cacheActorService->method('getFromAccount')->with('alice')->willReturn($actor);

		$response = $this->controller()->$page('alice');

		$this->assertInstanceOf(PublicTemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['application' => 'Alice Wonder - Social'], $response->getParams());
		$this->assertSame(['public' => true], $this->states['social']['serverData']);
	}

	public function testAnAccountWithNoDisplayNameIsTitledByItsUsername(): void {
		$actor = $this->createMock(Person::class);
		$actor->method('getName')->willReturn('');
		$actor->method('getPreferredUsername')->willReturn('alice');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);

		$this->assertSame(['application' => 'alice - Social'], $this->controller()->actor('alice')->getParams());
	}

	#[DataProvider('publicPages')]
	public function testPublicPagesOfAnUnknownAccountAre404(string $page): void {
		$this->cacheActorService->method('getFromAccount')
			->willThrowException(new CacheActorDoesNotExistException());

		$response = $this->controller()->$page('ghost');

		$this->assertInstanceOf(PublicTemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	#[DataProvider('publicPages')]
	public function testPublicPagesReportUnexpectedLookupFailures(string $page): void {
		$this->cacheActorService->method('getFromAccount')->with('alice')->willThrowException(new \RuntimeException('db down'));

		$response = $this->controller()->$page('alice');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(-1, $response->getData()['status']);
		$this->assertSame('request failed', $response->getData()['error']);
		$this->assertArrayNotHasKey('exception', $response->getData(), 'internals must not leak');
	}

	/**
	 * The post page used to have a second, unrouted renderer here that read
	 * the post with the visibility filter off. The one route that serves a
	 * post is `ActivityPubController::displayPost()`, which reads it as the
	 * viewer, and this controller no longer knows how to read a post at all.
	 */
	public function testThisControllerDoesNotRenderPosts(): void {
		$this->assertFalse(method_exists(SocialPubController::class, 'displayPost'));
	}
}
