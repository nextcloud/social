<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\OStatusController;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\RetrieveAccountFormatException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OStatusControllerTest extends TestCase {
	/** @var IInitialStateService&MockObject */
	private $initialStateService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CurlService&MockObject */
	private $curlService;
	/** @var IUserSession&MockObject */
	private $userSession;
	private OStatusController $controller;
	private array $states = [];

	protected function setUp(): void {
		$this->initialStateService = $this->createMock(IInitialStateService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->initialStateService->method('provideInitialState')
			->willReturnCallback(function (string $app, string $key, $data): void {
				$this->states[$app][$key] = $data;
			});

		$this->controller = new OStatusController(
			$this->createMock(IRequest::class),
			$this->initialStateService,
			$this->cacheActorService,
			$this->accountService,
			$this->curlService,
			$this->createMock(MiscService::class),
			$this->userSession
		);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function loggedIn(string $uid, string $displayName): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/** @return Person&MockObject */
	private function actorWithAccount(string $account): Person {
		$actor = $this->createMock(Person::class);
		$actor->method('getAccount')->willReturn($account);

		return $actor;
	}

	private function assertFailure(DataResponse $response, string $exceptionClass): void {
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(-1, $response->getData()['status']);
		$this->assertSame($exceptionClass, $response->getData()['exception']);
	}

	public function testSubscribeRendersTheAppWithTargetAccountAndCurrentUser(): void {
		$this->loggedIn('alice', 'Alice A.');
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example')
			->willReturn($this->actorWithAccount('bob@remote.example'));

		$response = $this->controller->subscribe('bob@remote.example');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('main', $response->getTemplateName());
		$this->assertSame([
			'account' => 'bob@remote.example',
			'currentUser' => ['uid' => 'alice', 'displayName' => 'Alice A.'],
		], $this->states['social']['serverData']);
	}

	public function testSubscribeFallsBackToActorIdWhenUriIsNotAnAccount(): void {
		$this->loggedIn('alice', 'Alice');
		$this->cacheActorService->method('getFromAccount')->willThrowException(new InvalidResourceException());
		$this->cacheActorService->expects($this->once())->method('getFromId')->with('https://remote.example/users/bob')
			->willReturn($this->actorWithAccount('bob@remote.example'));

		$response = $this->controller->subscribe('https://remote.example/users/bob');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('bob@remote.example', $this->states['social']['serverData']['account']);
	}

	public function testSubscribeFailsWithoutASessionUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->cacheActorService->method('getFromAccount')->willReturn($this->actorWithAccount('bob@remote.example'));

		$response = $this->controller->subscribe('bob@remote.example');

		$this->assertFailure($response, \Exception::class);
		$this->assertSame('Failed to retrieve current user', $response->getData()['message']);
	}

	public function testSubscribeFailsForUnknownActor(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertFailure($this->controller->subscribe('ghost@remote.example'), CacheActorDoesNotExistException::class);
	}

	public function testFollowRemoteRendersAGuestPageForTheLocalAccount(): void {
		$this->accountService->method('getActor')->with('alice')->willReturn($this->actorWithAccount('alice@cloud.example'));

		$response = $this->controller->followRemote('alice');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('guest', $response->getRenderAs());
		$this->assertSame(['local' => 'alice', 'account' => 'alice@cloud.example'], $this->states['social']['serverData']);
	}

	public function testFollowRemoteFailsForUnknownLocalAccount(): void {
		$this->accountService->method('getActor')->willThrowException(new AccountDoesNotExistException());

		$this->assertFailure($this->controller->followRemote('ghost'), AccountDoesNotExistException::class);
	}

	public function testGetLinkBuildsTheRemoteSubscribeUrlFromWebfinger(): void {
		$this->accountService->method('getActor')->with('alice')->willReturn($this->actorWithAccount('alice@cloud.example'));
		$this->curlService->method('webfingerAccount')->willReturn([
			'links' => [
				['rel' => 'self', 'href' => 'https://remote.example/users/bob'],
				['rel' => 'http://ostatus.org/schema/1.0/subscribe', 'template' => 'https://remote.example/authorize_interaction?uri={uri}'],
			],
		]);

		$response = $this->controller->getLink('alice', 'bob@remote.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['result' => ['url' => 'https://remote.example/authorize_interaction?uri=alice@cloud.example'], 'status' => 1],
			$response->getData()
		);
	}

	public function testGetLinkFailsWhenRemoteHasNoSubscribeTemplate(): void {
		$this->accountService->method('getActor')->willReturn($this->actorWithAccount('alice@cloud.example'));
		$this->curlService->method('webfingerAccount')->willReturn(['links' => [['rel' => 'self', 'href' => 'x']]]);

		$this->assertFailure($this->controller->getLink('alice', 'bob@remote.example'), RetrieveAccountFormatException::class);
	}

	public function testGetLinkFailsWhenWebfingerFails(): void {
		$this->accountService->method('getActor')->willReturn($this->actorWithAccount('alice@cloud.example'));
		$this->curlService->method('webfingerAccount')->willThrowException(new \RuntimeException('unreachable'));

		$this->assertFailure($this->controller->getLink('alice', 'bob@remote.example'), \RuntimeException::class);
	}
}
