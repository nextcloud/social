<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ActivityPubController;
use OCA\Social\Controller\SocialPubController;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\TooManyRequestsException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\InboxLimiter;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * TAsync::async() closes PHPUnit's output buffer and sends headers; the
 * controller under test is subclassed so the call is recorded instead.
 */
class AsyncFreeActivityPubController extends ActivityPubController {
	public int $asyncCalls = 0;

	public function async(string $result = ''): void {
		$this->asyncCalls++;
	}
}

class ActivityPubControllerTest extends TestCase {
	private const SOCIAL_URL = 'https://cloud.example/apps/social/';
	private const LD_JSON = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

	/** @var IRequest&MockObject */
	private $request;
	/** @var SocialPubController&MockObject */
	private $socialPubController;
	/** @var FediverseService&MockObject */
	private $fediverseService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var SignatureService&MockObject */
	private $signatureService;
	/** @var StreamQueueService&MockObject */
	private $streamQueueService;
	/** @var ImportService&MockObject */
	private $importService;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var FollowService&MockObject */
	private $followService;
	/** @var StreamService&MockObject */
	private $streamService;
	/** @var ConfigService&MockObject */
	private $configService;
	/** @var IInitialStateService&MockObject */
	private $initialStateService;
	/** @var InboxLimiter&MockObject */
	private $inboxLimiter;
	private AsyncFreeActivityPubController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->socialPubController = $this->createMock(SocialPubController::class);
		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->signatureService = $this->createMock(SignatureService::class);
		$this->streamQueueService = $this->createMock(StreamQueueService::class);
		$this->importService = $this->createMock(ImportService::class);
		$this->inboxLimiter = $this->createMock(InboxLimiter::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->initialStateService = $this->createMock(IInitialStateService::class);

		$this->configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);

		// Response::getHeaders() stamps X-Request-Id from the container's request
		\OC::$server->register(IRequest::class, $this->request);

		$this->controller = new AsyncFreeActivityPubController(
			$this->request,
			$this->socialPubController,
			$this->fediverseService,
			$this->cacheActorService,
			$this->signatureService,
			$this->streamQueueService,
			$this->importService,
			$this->inboxLimiter,
			$this->accountService,
			$this->followService,
			$this->streamService,
			$this->configService,
			$this->initialStateService,
			new NullLogger()
		);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function acceptHeader(string $accept): void {
		$this->request->method('getHeader')->with('Accept')->willReturn($accept);
	}

	/** @return Person&MockObject */
	private function localActor(string $username, ?Person $actor = null): Person {
		$actor ??= $this->createMock(Person::class);
		$this->cacheActorService->method('getFromLocalAccount')->with($username)->willReturn($actor);

		return $actor;
	}

	private function assertActivityPubResponse(DataResponse $response, object $expectedData): void {
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($expectedData, $response->getData());
		$this->assertSame(self::LD_JSON, $response->getHeaders()['Content-Type']);
	}

	private function assertFailure(DataResponse $response, string $exceptionClass, int $status = Http::STATUS_INTERNAL_SERVER_ERROR): void {
		$this->assertSame($status, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(-1, $data['status']);
		// several of these routes are public: the class and message of the
		// exception ($exceptionClass here) must never reach the response
		$this->assertSame('request failed', $data['error']);
		$this->assertArrayNotHasKey('exception', $data);
		$this->assertArrayNotHasKey('message', $data);
	}

	// actor()

	/** @return iterable<string, array{string}> */
	public function activityStreamsAcceptHeaders(): iterable {
		yield 'activity+json' => ['application/activity+json'];
		yield 'ld+json with profile' => ['application/ld+json; profile="https://www.w3.org/ns/activitystreams"'];
		yield 'ld+json among other types' => ['text/html, application/ld+json;q=0.9'];
		yield 'whitespace around type' => [' application/activity+json '];
	}

	/** @dataProvider activityStreamsAcceptHeaders */
	public function testActorReturnsActivityPubJsonForActivityStreamsClients(string $accept): void {
		$this->acceptHeader($accept);
		$actor = $this->localActor('alice');
		$actor->expects($this->once())->method('setDisplayW3ContextSecurity')->with(true);
		$this->socialPubController->expects($this->never())->method('actor');

		$response = $this->controller->actor('alice');

		$this->assertActivityPubResponse($response, $actor);
	}

	/** @return iterable<string, array{string}> */
	public function humanAcceptHeaders(): iterable {
		yield 'browser' => ['text/html,application/xhtml+xml,*/*;q=0.8'];
		yield 'plain json' => ['application/json'];
		yield 'no header' => [''];
	}

	/** @dataProvider humanAcceptHeaders */
	public function testActorFallsBackToPublicPageForBrowsers(string $accept): void {
		$this->acceptHeader($accept);
		$page = new TemplateResponse('social', 'main');
		$this->socialPubController->expects($this->once())->method('actor')->with('alice')->willReturn($page);
		$this->cacheActorService->expects($this->never())->method('getFromLocalAccount');

		$this->assertSame($page, $this->controller->actor('alice'));
	}

	public function testActorAliasBehavesLikeActor(): void {
		$this->acceptHeader('application/activity+json');
		$actor = $this->localActor('alice');

		$this->assertActivityPubResponse($this->controller->actorAlias('alice'), $actor);
	}

	public function testActorAliasFallsBackToPublicPageForBrowsers(): void {
		$this->acceptHeader('text/html');
		$page = new TemplateResponse('social', 'main');
		$this->socialPubController->method('actor')->with('bob')->willReturn($page);

		$this->assertSame($page, $this->controller->actorAlias('bob'));
	}

	public function testUnknownActorIsA404(): void {
		$this->acceptHeader('application/activity+json');
		$this->cacheActorService->method('getFromLocalAccount')
			->willThrowException(new CacheActorDoesNotExistException('nope'));

		$response = $this->controller->actor('ghost');

		$this->assertFailure($response, CacheActorDoesNotExistException::class, Http::STATUS_NOT_FOUND);
	}

	// sharedInbox() / inbox()

	private function signedRequestFrom(string $origin, int $time = 1700000000): void {
		$this->signatureService->method('checkRequest')
			->willReturnCallback(function (IRequest $request, string $body, int &$requestTime) use ($origin, $time): string {
				$requestTime = $time;

				return $origin;
			});
	}

	/** @return ACore&MockObject */
	private function incomingActivity(string $token = 'req-token'): ACore {
		$activity = $this->createMock(ACore::class);
		$activity->method('getRequestToken')->willReturn($token);
		$this->importService->method('importFromJson')->willReturn($activity);

		return $activity;
	}

	public function testAThrottledSharedInboxDeliveryIs429AndSkipsSignatureWork(): void {
		$this->inboxLimiter->method('assertAllowed')
			->willThrowException(new TooManyRequestsException());
		$this->signatureService->expects($this->never())->method('checkRequest');
		$this->importService->expects($this->never())->method('importFromJson');

		$response = $this->controller->sharedInbox();

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
	}

	public function testAThrottledUserInboxDeliveryIs429(): void {
		$this->inboxLimiter->method('assertAllowed')
			->willThrowException(new TooManyRequestsException());
		$this->importService->expects($this->never())->method('importFromJson');

		$this->assertSame(
			Http::STATUS_TOO_MANY_REQUESTS,
			$this->controller->inbox('alice')->getStatus()
		);
	}

	public function testSharedInboxRejectsRequestsWithInvalidSignature(): void {
		$this->signatureService->method('checkRequest')->willThrowException(new SignatureException('bad signature'));
		$this->importService->expects($this->never())->method('importFromJson');
		$this->importService->expects($this->never())->method('parseIncomingRequest');

		$response = $this->controller->sharedInbox();

		$this->assertFailure($response, SignatureException::class);
		$this->assertSame(0, $this->controller->asyncCalls);
	}

	public function testSharedInboxAcknowledgesActivitiesFromGoneActorsWithoutImporting(): void {
		$this->signatureService->method('checkRequest')->willThrowException(new SignatureIsGoneException());
		$this->importService->expects($this->never())->method('importFromJson');

		$response = $this->controller->sharedInbox();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['result' => [], 'status' => 1], $response->getData());
	}

	public function testSharedInboxRejectsUnauthorizedInstances(): void {
		$this->signedRequestFrom('https://blocked.example');
		$this->fediverseService->method('authorized')->with('https://blocked.example')
			->willThrowException(new UnauthorizedFediverseException('blocked'));
		$this->importService->expects($this->never())->method('importFromJson');

		$this->assertFailure($this->controller->sharedInbox(), UnauthorizedFediverseException::class);
	}

	public function testSharedInboxImportsTheActivityAndTagsItsOrigin(): void {
		$this->signedRequestFrom('https://remote.example', 1234);
		$this->fediverseService->expects($this->once())->method('authorized')->with('https://remote.example')->willReturn(true);
		$activity = $this->incomingActivity('tok-1');
		$this->signatureService->method('checkObject')->with($activity)->willReturn(false);
		$activity->expects($this->once())->method('setOrigin')
			->with('https://remote.example', SignatureService::ORIGIN_HEADER, 1234);
		$this->importService->expects($this->once())->method('parseIncomingRequest')->with($activity);
		$this->streamQueueService->expects($this->once())->method('cacheStreamByToken')->with('tok-1');

		$response = $this->controller->sharedInbox();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['status']);
		$this->assertSame(1, $this->controller->asyncCalls, 'response is flushed before the queue is processed');
	}

	public function testSharedInboxKeepsLinkedDataSignatureOriginWhenPresent(): void {
		$this->signedRequestFrom('https://remote.example');
		$activity = $this->incomingActivity();
		$this->signatureService->method('checkObject')->willReturn(true);
		$activity->expects($this->never())->method('setOrigin');

		$this->assertSame(Http::STATUS_OK, $this->controller->sharedInbox()->getStatus());
	}

	public function testSharedInboxIgnoresUnknownActivityTypes(): void {
		$this->signedRequestFrom('https://remote.example');
		$this->incomingActivity('tok-2');
		$this->importService->method('parseIncomingRequest')->willThrowException(new ItemUnknownException());
		$this->streamQueueService->expects($this->once())->method('cacheStreamByToken')->with('tok-2');

		$response = $this->controller->sharedInbox();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['status']);
	}

	public function testInboxRejectsRequestsWithInvalidSignature(): void {
		$this->signatureService->method('checkRequest')->willThrowException(new SignatureException('bad'));
		$this->cacheActorService->expects($this->never())->method('getFromLocalAccount');
		$this->importService->expects($this->never())->method('importFromJson');

		$this->assertFailure($this->controller->inbox('alice'), SignatureException::class);
	}

	public function testInboxFailsForUnknownLocalUser(): void {
		$this->signedRequestFrom('https://remote.example');
		$this->cacheActorService->method('getFromLocalAccount')->with('ghost')
			->willThrowException(new CacheActorDoesNotExistException());
		$this->importService->expects($this->never())->method('importFromJson');

		$this->assertFailure($this->controller->inbox('ghost'), CacheActorDoesNotExistException::class);
	}

	public function testInboxImportsActivityForExistingLocalUser(): void {
		$this->signedRequestFrom('https://remote.example', 99);
		$this->localActor('alice');
		$activity = $this->incomingActivity('tok-3');
		$this->signatureService->method('checkObject')->willReturn(false);
		$activity->expects($this->once())->method('setOrigin')->with('https://remote.example', SignatureService::ORIGIN_HEADER, 99);
		$this->importService->expects($this->once())->method('parseIncomingRequest')->with($activity);
		$this->streamQueueService->expects($this->once())->method('cacheStreamByToken')->with('tok-3');

		$response = $this->controller->inbox('alice');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $this->controller->asyncCalls);
	}

	public function testInboxAcknowledgesGoneSignatures(): void {
		$this->signatureService->method('checkRequest')->willThrowException(new SignatureIsGoneException());

		$response = $this->controller->inbox('alice');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['status']);
	}

	// getInbox()

	public function testGetInboxReturnsAnEmptyOrderedCollection(): void {
		$actor = $this->localActor('alice');
		$actor->method('getInbox')->willReturn('https://cloud.example/apps/social/@alice/inbox');

		$response = $this->controller->getInbox('alice');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::LD_JSON, $response->getHeaders()['Content-Type']);
		$collection = $response->getData();
		$this->assertInstanceOf(OrderedCollection::class, $collection);
		$this->assertSame('https://cloud.example/apps/social/@alice/inbox', $collection->getId());
		$this->assertSame(0, $collection->getTotalItems());
		$this->assertSame('OrderedCollection', $collection->jsonSerialize()['type']);
	}

	public function testGetInboxOfUnknownUserIs404(): void {
		$this->cacheActorService->method('getFromLocalAccount')->willThrowException(new CacheActorDoesNotExistException());

		$response = $this->controller->getInbox('ghost');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	// outbox() / followers() / following()

	public function testOutboxReturnsTheActorsOutboxCollection(): void {
		$actor = $this->localActor('alice');
		$collection = new OrderedCollection();
		$this->streamService->method('getOutboxCollection')->with($actor)->willReturn($collection);

		$this->assertActivityPubResponse($this->controller->outbox('alice'), $collection);
	}

	public function testOutboxOfUnknownUserFails(): void {
		$this->cacheActorService->method('getFromLocalAccount')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertFailure($this->controller->outbox('ghost'), CacheActorDoesNotExistException::class);
	}

	public function testFollowersReturnsCollectionForActivityStreamsClients(): void {
		$this->acceptHeader('application/activity+json');
		$actor = $this->localActor('alice');
		$collection = new OrderedCollection();
		$this->followService->method('getFollowersCollection')->with($actor)->willReturn($collection);

		$this->assertActivityPubResponse($this->controller->followers('alice'), $collection);
	}

	public function testFollowersFallsBackToPublicPageForBrowsers(): void {
		$this->acceptHeader('text/html');
		$page = new TemplateResponse('social', 'main');
		$this->socialPubController->expects($this->once())->method('followers')->with('alice')->willReturn($page);
		$this->followService->expects($this->never())->method('getFollowersCollection');

		$this->assertSame($page, $this->controller->followers('alice'));
	}

	public function testFollowingReturnsCollectionForActivityStreamsClients(): void {
		$this->acceptHeader('application/ld+json');
		$actor = $this->localActor('alice');
		$collection = new OrderedCollection();
		$this->followService->method('getFollowingCollection')->with($actor)->willReturn($collection);

		$this->assertActivityPubResponse($this->controller->following('alice'), $collection);
	}

	public function testFollowingFallsBackToPublicPageForBrowsers(): void {
		$this->acceptHeader('text/html');
		$page = new TemplateResponse('social', 'main');
		$this->socialPubController->expects($this->once())->method('following')->with('alice')->willReturn($page);

		$this->assertSame($page, $this->controller->following('alice'));
	}

	public function testFollowersOfUnknownUserFails(): void {
		$this->acceptHeader('application/activity+json');
		$this->cacheActorService->method('getFromLocalAccount')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertFailure($this->controller->followers('ghost'), CacheActorDoesNotExistException::class);
	}

	// displayPost()

	/** @return iterable<string, array{string, string}> */
	public function reservedTokens(): iterable {
		yield 'outbox' => ['outbox', 'getOutboxCollection'];
		yield 'Outbox, mixed case' => ['Outbox', 'getOutboxCollection'];
	}

	/** @dataProvider reservedTokens */
	public function testDisplayPostRoutesReservedTokensToTheCollections(string $token, string $method): void {
		$this->acceptHeader('application/activity+json');
		$actor = $this->localActor('alice');
		$collection = new OrderedCollection();
		$this->streamService->expects($this->once())->method($method)->with($actor)->willReturn($collection);
		$this->streamService->expects($this->never())->method('getStreamById');

		$this->assertActivityPubResponse($this->controller->displayPost('alice', $token), $collection);
	}

	public function testDisplayPostRoutesFollowersTokenToTheFollowersCollection(): void {
		$this->acceptHeader('application/activity+json');
		$actor = $this->localActor('alice');
		$collection = new OrderedCollection();
		$this->followService->expects($this->once())->method('getFollowersCollection')->with($actor)->willReturn($collection);

		$this->assertActivityPubResponse($this->controller->displayPost('alice', 'FOLLOWERS'), $collection);
	}

	public function testDisplayPostRoutesFollowingTokenToTheFollowingCollection(): void {
		$this->acceptHeader('application/activity+json');
		$actor = $this->localActor('alice');
		$collection = new OrderedCollection();
		$this->followService->expects($this->once())->method('getFollowingCollection')->with($actor)->willReturn($collection);

		$this->assertActivityPubResponse($this->controller->displayPost('alice', 'following'), $collection);
	}

	public function testDisplayPostReturnsTheStreamAsActivityPub(): void {
		$this->acceptHeader('application/activity+json');
		$viewer = $this->createMock(Person::class);
		$this->accountService->method('getCurrentViewer')->willReturn($viewer);
		$this->streamService->expects($this->once())->method('setViewer')->with($viewer);
		$stream = $this->createMock(Stream::class);
		$this->streamService->method('getStreamById')->with(self::SOCIAL_URL . '@alice/abc123', true)->willReturn($stream);
		$stream->expects($this->once())->method('setCompleteDetails')->with(false);

		$this->assertActivityPubResponse($this->controller->displayPost('alice', 'abc123'), $stream);
	}

	public function testDisplayPostWorksForAnonymousActivityPubClients(): void {
		$this->acceptHeader('application/activity+json');
		$this->accountService->method('getCurrentViewer')->willThrowException(new AccountDoesNotExistException());
		$this->streamService->expects($this->never())->method('setViewer');
		$stream = $this->createMock(Stream::class);
		$this->streamService->method('getStreamById')->willReturn($stream);

		$this->assertActivityPubResponse($this->controller->displayPost('alice', 'abc123'), $stream);
	}

	public function testDisplayPostOfUnknownStreamIs404WithTheLookedUpId(): void {
		$this->acceptHeader('application/activity+json');
		$this->accountService->method('getCurrentViewer')->willThrowException(new AccountDoesNotExistException());
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$response = $this->controller->displayPost('alice', 'missing');

		$this->assertFailure($response, StreamNotFoundException::class, Http::STATUS_NOT_FOUND);
		$this->assertSame(self::SOCIAL_URL . '@alice/missing', $response->getData()['stream']);
	}

	public function testDisplayPostRendersPublicPageWithTheItemForBrowsers(): void {
		$this->acceptHeader('text/html');
		$stream = $this->createMock(Stream::class);
		$this->streamService->method('getStreamById')->with(self::SOCIAL_URL . '@alice/abc123', true)->willReturn($stream);

		$states = [];
		$this->initialStateService->method('provideInitialState')
			->willReturnCallback(function (string $app, string $key, $data) use (&$states): void {
				$states[$app][$key] = $data;
			});

		$response = $this->controller->displayPost('alice', 'abc123');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('main', $response->getTemplateName());
		$this->assertSame(['public' => true, 'firstrun' => false, 'setup' => false], $states['social']['serverData']);
		$this->assertSame($stream, $states['social']['item']);
	}

	public function testDisplayPostRendersPublicPageWithoutItemWhenStreamIsUnknown(): void {
		$this->acceptHeader('text/html');
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$keys = [];
		$this->initialStateService->method('provideInitialState')
			->willReturnCallback(function (string $app, string $key, $data) use (&$keys): void {
				$keys[] = $key;
			});

		$response = $this->controller->displayPost('alice', 'missing');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(['serverData'], $keys);
	}
}
