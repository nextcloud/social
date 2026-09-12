<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\NavigationController;
use OCA\Social\Controller\SocialPubController;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SocialPubControllerTest extends TestCase {
	private const SOCIAL_URL = 'https://cloud.example/apps/social/';

	/** @var IInitialState&MockObject */
	private $initialState;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var StreamService&MockObject */
	private $streamService;
	private array $states = [];

	protected function setUp(): void {
		$this->initialState = $this->createMock(IInitialState::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->streamService = $this->createMock(StreamService::class);

		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $data): void {
				$this->states['social'][$key] = $data;
			});
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function controller(?string $userId): SocialPubController {
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);

		return new SocialPubController(
			$userId,
			$this->initialState,
			$this->createMock(IRequest::class),
			$this->createMock(IL10N::class),
			$this->createMock(NavigationController::class),
			$this->cacheActorService,
			$this->accountService,
			$this->streamService,
			$configService
		);
	}

	/** @return Stream&MockObject */
	private function streamBy(string $displayName, string $preferredUsername): Stream {
		$author = $this->createMock(Person::class);
		$author->method('getDisplayName')->willReturn($displayName);
		$author->method('getPreferredUsername')->willReturn($preferredUsername);
		$stream = $this->createMock(Stream::class);
		$stream->method('getActor')->willReturn($author);

		return $stream;
	}

	public function testDisplayPostRendersTheStreamForItsAuthor(): void {
		$viewer = $this->createMock(Person::class);
		$this->accountService->method('getCurrentViewer')->willReturn($viewer);
		$this->streamService->expects($this->once())->method('setViewer')->with($viewer);
		$stream = $this->streamBy('Alice', 'alice');
		$this->streamService->method('getStreamById')->with(self::SOCIAL_URL . '@alice/abc', false)->willReturn($stream);
		$stream->expects($this->once())->method('setCompleteDetails')->with(true);
		$stream->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);

		$response = $this->controller('alice')->displayPost('alice', 'abc');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('main', $response->getTemplateName());
		$this->assertSame(['application' => 'Social'], $response->getParams());
		$this->assertSame($stream, $this->states['social']['item']);
		$this->assertSame(['public' => false], $this->states['social']['serverData']);
	}

	public function testDisplayPostIsPublicForAnonymousVisitors(): void {
		$this->accountService->method('getCurrentViewer')->willThrowException(new AccountDoesNotExistException());
		$this->streamService->expects($this->never())->method('setViewer');
		$this->streamService->method('getStreamById')->willReturn($this->streamBy('Alice', 'alice'));

		$this->controller(null)->displayPost('alice', 'abc');

		$this->assertSame(['public' => true], $this->states['social']['serverData']);
	}

	public function testDisplayPostMatchesTheAuthorCaseInsensitively(): void {
		$this->accountService->method('getCurrentViewer')->willThrowException(new AccountDoesNotExistException());
		$this->streamService->method('getStreamById')->willReturn($this->streamBy('Alice Wonder', 'ALICE'));

		$this->assertInstanceOf(TemplateResponse::class, $this->controller(null)->displayPost('alice', 'abc'));
	}

	public function testDisplayPostRefusesAStreamOfAnotherAuthor(): void {
		$this->accountService->method('getCurrentViewer')->willThrowException(new AccountDoesNotExistException());
		$this->streamService->method('getStreamById')->willReturn($this->streamBy('Bob', 'bob'));

		$this->expectException(StreamNotFoundException::class);

		$this->controller(null)->displayPost('alice', 'abc');
	}

	public function testDisplayPostPropagatesUnknownStreams(): void {
		$this->accountService->method('getCurrentViewer')->willThrowException(new AccountDoesNotExistException());
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$this->expectException(StreamNotFoundException::class);

		$this->controller(null)->displayPost('alice', 'missing');
	}

	/** @return iterable<string, array{string}> */
	public static function publicPages(): iterable {
		yield 'actor' => ['actor'];
		yield 'followers' => ['followers'];
		yield 'following' => ['following'];
	}

	/** @dataProvider publicPages */
	public function testPublicPagesReportUnexpectedLookupFailures(string $page): void {
		$this->cacheActorService->method('getFromAccount')->with('alice')->willThrowException(new \RuntimeException('db down'));

		$response = $this->controller(null)->$page('alice');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(-1, $response->getData()['status']);
		$this->assertSame('request failed', $response->getData()['error']);
		$this->assertArrayNotHasKey('exception', $response->getData(), 'internals must not leak');
	}
}
