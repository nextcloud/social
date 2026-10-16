<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\AvatarController;
use OCA\Social\Service\AvatarService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The avatar of a local account by its username, which is what the actor's
 * icon and the account's `avatar` point at.
 */
class AvatarControllerTest extends TestCase {
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createStub(IRequest::class);
		// a response reads the request it answers when it builds its headers
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
		parent::tearDown();
	}

	private function controller(AvatarService $avatarService): AvatarController {
		return new AvatarController($this->request, $avatarService, new NullLogger());
	}

	public function testServesThePictureForADayWithItsType(): void {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getMimeType')->willReturn('image/jpeg');
		$avatarService = $this->createMock(AvatarService::class);
		$avatarService->expects($this->once())->method('published')->with('sunflower', 512)->willReturn($file);

		$response = $this->controller($avatarService)->avatar('sunflower', 128);

		$this->assertInstanceOf(FileDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$headers = $response->getHeaders();
		$this->assertSame('image/jpeg', $headers['Content-Type']);
		// private: what a visitor is shown can differ from what a reader who
		// is signed in is shown, so no shared cache may keep it
		$this->assertSame('private, max-age=86400, immutable', $headers['Cache-Control']);
	}

	public function testRoundsTheSizeToTheOnesNextcloudStores(): void {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getMimeType')->willReturn('image/png');
		$avatarService = $this->createMock(AvatarService::class);
		$avatarService->expects($this->exactly(2))->method('published')
			->with('sunflower', $this->logicalOr(64, 512))->willReturn($file);

		$this->controller($avatarService)->avatar('sunflower', 32);
		$this->controller($avatarService)->avatar('sunflower', 9000);
	}

	public function testAnAccountWithoutAPictureIsNotFound(): void {
		$avatarService = $this->createStub(AvatarService::class);
		$avatarService->method('published')->willThrowException(new NotFoundException());

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($avatarService)->avatar('nobody', 128)->getStatus());
	}

	public function testAPictureThatCannotBeReadIsNotFound(): void {
		$avatarService = $this->createStub(AvatarService::class);
		$avatarService->method('published')->willThrowException(new RuntimeException('disk'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($avatarService)->avatar('sunflower', 128)->getStatus());
	}
}
