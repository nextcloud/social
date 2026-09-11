<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\NotificationController;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\NotificationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The routes behind the dismiss button.
 *
 * Checked here is what a caller is refused as much as what they get back: no
 * credentials is a 401, a token without the granular scope is a 403, a
 * notification that is not the caller's is a 404 that says nothing about
 * whether it exists, and dismissing one that is already gone is a success —
 * the client is asking for a state that already holds.
 */
class NotificationControllerTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	/** @var IRequest&MockObject */
	private $request;
	private AccountService|MockObject $accountService;
	private ClientService|MockObject $clientService;
	private NotificationService|MockObject $notificationService;
	private IUserSession|MockObject $userSession;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var int[] the notification ids the viewer has */
	private array $own = [7];
	/** @var array<int, array> [method, id] of every call that changes something */
	private array $writes = [];
	private bool $csrf = true;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createMock(AccountService::class);
		$this->accountService->method('getActorFromUserId')->willReturnCallback(
			function (): Person {
				$viewer = new Person();
				$viewer->setId(self::VIEWER);

				return $viewer;
			}
		);

		$this->clientService = $this->createMock(ClientService::class);

		$this->notificationService = $this->createMock(NotificationService::class);
		$this->notificationService->method('get')
			->willReturnCallback(function (Person $viewer, int $id): Stream {
				$this->mine($id);

				$notification = new SocialAppNotification();
				$notification->setNid($id);

				return $notification;
			});
		$this->notificationService->method('dismiss')
			->willReturnCallback(function (Person $viewer, int $id): void {
				$this->mine($id);
				$this->writes[] = ['dismiss', $id];
			});
		$this->notificationService->method('clear')
			->willReturnCallback(function (): int {
				$this->writes[] = ['clear', 0];

				return 3;
			});

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/**
	 * Built per test rather than in setUp(): the controller reads the
	 * Authorization header in its constructor, so a token set afterwards would
	 * never be seen.
	 */
	private function controller(): NotificationController {
		return new NotificationController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->clientService,
			$this->notificationService
		);
	}

	/** @throws ItemNotFoundException a notification that is not the viewer's */
	private function mine(int $id): void {
		if (!in_array($id, $this->own, true)) {
			throw new ItemNotFoundException('Record not found');
		}
	}

	/** @param string[] $scopes */
	private function withToken(array $scopes): void {
		$this->headers['Authorization'] = 'Bearer token-1';

		$client = $this->createMock(SocialClient::class);
		$client->method('getAuthUserId')->willReturn('alice');
		$client->method('getAuthScopes')->willReturn($scopes);
		$this->clientService->method('getFromToken')->willReturn($client);
	}

	private function withoutCredentials(): void {
		// no bearer token, and a session that fails the CSRF check is no
		// session at all as far as this API is concerned
		$this->csrf = false;
	}

	public function testGetAnswersTheNotification(): void {
		$response = $this->controller()->get(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(Stream::class, $response->getData());
		$this->assertSame(7, $response->getData()->getNid());
	}

	public function testGetOfANotificationThatIsNotTheViewersIsNotFound(): void {
		$response = $this->controller()->get(8);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Record not found'], $response->getData());
	}

	public function testGetWithoutCredentialsIsRefused(): void {
		$this->withoutCredentials();

		$response = $this->controller()->get(7);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertArrayHasKey('WWW-Authenticate', $response->getHeaders());
	}

	public function testGetNeedsAReadScope(): void {
		$this->withToken(['write:notifications']);

		$response = $this->controller()->get(7);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDismissRemovesTheNotification(): void {
		$this->withToken(['write:notifications']);

		$response = $this->controller()->dismiss(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame([['dismiss', 7]], $this->writes);
	}

	public function testDismissingSomethingAlreadyGoneIsASuccess(): void {
		$response = $this->controller()->dismiss(8);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testDismissNeedsAWriteScope(): void {
		$this->withToken(['read']);

		$response = $this->controller()->dismiss(7);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testDismissWithoutCredentialsIsRefused(): void {
		$this->withoutCredentials();

		$response = $this->controller()->dismiss(7);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testClearEmptiesTheList(): void {
		$this->withToken(['write:notifications']);

		$response = $this->controller()->clear();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
		$this->assertSame([['clear', 0]], $this->writes);
	}

	public function testTheBroadWriteScopeCoversClearing(): void {
		$this->withToken(['write']);

		$response = $this->controller()->clear();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['clear', 0]], $this->writes);
	}

	public function testAnotherGranularWriteScopeDoesNotCoverClearing(): void {
		$this->withToken(['write:statuses']);

		$response = $this->controller()->clear();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testClearWithoutCredentialsIsRefused(): void {
		$this->withoutCredentials();

		$response = $this->controller()->clear();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->writes);
	}

	public function testAFailureOnThisSideSaysNothingAboutItself(): void {
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->notificationService->method('clear')
			->willThrowException(new \RuntimeException('SQLSTATE[42S02] social_stream'));

		$response = $this->controller()->clear();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'internal server error'], $response->getData());
	}

	public function testARevokedTokenIsAnUnauthorizedAnswer(): void {
		$this->headers['Authorization'] = 'Bearer token-1';
		$this->clientService->method('getFromToken')
			->willThrowException(new ClientNotFoundException());

		$response = $this->controller()->get(7);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}
}
