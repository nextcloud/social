<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use Exception;
use OCA\Social\Controller\ActivityPubController;
use OCA\Social\Controller\SocialPubController;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActivityPubFormatException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\SignatureException;
use OCA\Social\Exceptions\SignatureIsGoneException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\TooManyRequestsException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\InstanceActor;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\QuoteAuthorization;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\OrderedCollectionPage;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AuthorizedFetchService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\InboxLimiter;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\PinService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StreamQueueService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Exceptions\DateTimeException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
	/** @var StreamRequest&MockObject */
	private $streamRequest;
	/** @var PinService&MockObject */
	private $pinService;
	/** @var InstanceActorService&MockObject */
	private $instanceActorService;
	private $authorizedFetchService;

	/** The remote account a signed GET resolved to, if a test says so. */
	private ?Person $signedReader = null;
	/** Whether this instance answers only signed GETs. */
	private bool $secureMode = false;
	/** @var ConfigService&MockObject */
	private $configService;
	/** @var IInitialState&MockObject */
	private $initialState;
	/** @var InboxLimiter&MockObject */
	private $inboxLimiter;
	/** @var LoggerInterface&MockObject */
	private $logger;
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
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->pinService = $this->createMock(PinService::class);
		$this->instanceActorService = $this->createMock(InstanceActorService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->configService->method('getSocialUrl')->willReturn(self::SOCIAL_URL);

		// Response::getHeaders() stamps X-Request-Id from the container's request
		\OC::$server->register(IRequest::class, $this->request);

		$this->authorizedFetchService = $this->createMock(AuthorizedFetchService::class);
		$this->authorizedFetchService->method('reader')->willReturnCallback(
			fn (): ?Person => $this->signedReader
		);
		$this->authorizedFetchService->method('assertReadable')->willReturnCallback(
			function (IRequest $request, ?Person $reader): void {
				if ($this->secureMode && $reader === null) {
					throw new SignatureException('signed requests only');
				}
			}
		);

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
			$this->streamRequest,
			$this->pinService,
			$this->instanceActorService,
			$this->authorizedFetchService,
			$this->configService,
			$this->initialState,
			$this->logger
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

	/**
	 * $status has no default: what a rejected delivery answers is the whole
	 * point of the inbox's error handling, so every caller states it.
	 */
	private function assertFailure(DataResponse $response, string $exceptionClass, int $status): void {
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
	public static function activityStreamsAcceptHeaders(): iterable {
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
	public static function humanAcceptHeaders(): iterable {
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

	private function signedRequestFrom(string $origin, int $time = 1700000000, string $signedBy = ''): void {
		$this->signatureService->method('checkRequest')
			->willReturnCallback(function (
				IRequest $request, string $body, int &$requestTime, string &$signer = '',
			) use ($origin, $time, $signedBy): string {
				$requestTime = $time;
				$signer = $signedBy;

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

	/**
	 * The per-origin ceiling is spent on the origin the signature proved, not on
	 * the keyId as it arrived: an unverified name is anyone's to write, and
	 * charging it would let a stranger throttle the instance it names.
	 */
	public function testTheOriginCeilingIsChargedOnlyAfterTheSignatureVerified(): void {
		$this->signedRequestFrom('remote.example');
		$this->inboxLimiter->expects($this->once())->method('assertOriginAllowed')
			->with('remote.example')
			->willThrowException(new TooManyRequestsException());
		$this->importService->expects($this->never())->method('importFromJson');

		$this->assertSame(
			Http::STATUS_TOO_MANY_REQUESTS,
			$this->controller->sharedInbox()->getStatus()
		);
	}

	public function testSharedInboxRejectsRequestsWithInvalidSignature(): void {
		$this->signatureService->method('checkRequest')->willThrowException(new SignatureException('bad signature'));
		$this->importService->expects($this->never())->method('importFromJson');
		$this->importService->expects($this->never())->method('parseIncomingRequest');

		$response = $this->controller->sharedInbox();

		// a signature that does not verify is not a fault of this server, and a
		// peer must not redeliver it: 500 had it retrying for two days
		$this->assertFailure($response, SignatureException::class, Http::STATUS_UNAUTHORIZED);
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

		// blocking an instance has to *end* its deliveries; answering 500 made
		// each one come back a dozen times
		$this->assertFailure(
			$this->controller->sharedInbox(), UnauthorizedFediverseException::class, Http::STATUS_FORBIDDEN
		);
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

	public function testSharedInboxMakesTheSignerAnswerForTheActivity(): void {
		// the host matching proves the server, not the person: without this the
		// holder of any account on a server can act as anybody else on it
		$this->signedRequestFrom('https://remote.example', 1234, 'https://remote.example/users/mallory');
		$activity = $this->incomingActivity('tok-1');
		$this->signatureService->method('checkObject')->willReturn(false);
		$this->signatureService->expects($this->once())->method('assertSignerSpeaksFor')
			->with('https://remote.example/users/mallory', $activity);

		$this->assertSame(Http::STATUS_OK, $this->controller->sharedInbox()->getStatus());
	}

	public function testSharedInboxRefusesAnActivityItsSignerMayNotSpeakFor(): void {
		$this->signedRequestFrom('https://remote.example', 1234, 'https://remote.example/users/mallory');
		$this->incomingActivity();
		$this->signatureService->method('checkObject')->willReturn(false);
		$this->signatureService->method('assertSignerSpeaksFor')
			->willThrowException(new InvalidOriginException('not yours'));

		$response = $this->controller->sharedInbox();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testSharedInboxLetsALinkedDataSignatureSpeakForAForwardedActivity(): void {
		// a relayed or forwarded activity is signed by the server that passed it
		// on; the signature on the object itself is what vouches for the actor
		$this->signedRequestFrom('https://relay.example', 1234, 'https://relay.example/actor');
		$this->incomingActivity();
		$this->signatureService->method('checkObject')->willReturn(true);
		$this->signatureService->expects($this->never())->method('assertSignerSpeaksFor');

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

	/**
	 * An activity with no handler used to be answered 200 with nothing written
	 * anywhere: the only symptom of a whole class of activity being ignored was
	 * that nothing happened, which is indistinguishable from the peer never
	 * having sent it. This line is the app's most useful federation diagnostic.
	 */
	public function testAnUnhandledActivityIsLoggedWithItsTypeAndOrigin(): void {
		$this->signedRequestFrom('remote.example');
		$activity = $this->incomingActivity();
		$activity->method('getType')->willReturn('Arrive');
		$activity->method('getId')->willReturn('https://remote.example/activities/1');
		$activity->method('getActorId')->willReturn('https://remote.example/users/bob');
		$activity->method('getObjectId')->willReturn('https://remote.example/places/1');
		$this->importService->method('parseIncomingRequest')
			->willThrowException(new ItemUnknownException('no interface'));

		$logged = [];
		$this->logger->expects($this->once())
			->method('notice')
			->willReturnCallback(function (string $message, array $context) use (&$logged): void {
				$logged = $context;
			});

		$this->assertSame(Http::STATUS_OK, $this->controller->sharedInbox()->getStatus());

		$this->assertSame('Arrive', $logged['activityType']);
		$this->assertSame('remote.example', $logged['origin']);
		$this->assertSame('https://remote.example/activities/1', $logged['activity']);
		$this->assertSame('https://remote.example/users/bob', $logged['actor']);
		$this->assertSame('https://remote.example/places/1', $logged['object']);
	}

	public function testAnUnhandledActivityOnAUserInboxIsLoggedToo(): void {
		$this->signedRequestFrom('remote.example');
		$this->localActor('alice');
		$activity = $this->incomingActivity();
		$activity->method('getType')->willReturn('Arrive');
		$this->importService->method('parseIncomingRequest')
			->willThrowException(new ItemUnknownException());

		$this->logger->expects($this->once())->method('notice');

		$this->assertSame(Http::STATUS_OK, $this->controller->inbox('alice')->getStatus());
	}

	/**
	 * An activity whose own `type` has no model here never became an item, so
	 * the import throws. That used to answer 500, which makes a peer redeliver
	 * something we will never understand for as long as its queue allows.
	 */
	public function testAnActivityOfAnEntirelyUnknownTypeIsAcceptedAndLogged(): void {
		$this->signedRequestFrom('remote.example');
		$this->importService->method('importFromJson')
			->willThrowException(new ItemUnknownException('Arrive'));
		$this->logger->expects($this->once())->method('notice');

		$response = $this->controller->sharedInbox();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['status']);
	}

	public function testInboxRejectsRequestsWithInvalidSignature(): void {
		$this->signatureService->method('checkRequest')->willThrowException(new SignatureException('bad'));
		$this->cacheActorService->expects($this->never())->method('getFromLocalAccount');
		$this->importService->expects($this->never())->method('importFromJson');

		$this->assertFailure(
			$this->controller->inbox('alice'), SignatureException::class, Http::STATUS_UNAUTHORIZED
		);
	}

	public function testInboxFailsForUnknownLocalUser(): void {
		$this->signedRequestFrom('https://remote.example');
		$this->cacheActorService->method('getFromLocalAccount')->with('ghost')
			->willThrowException(new CacheActorDoesNotExistException());
		$this->importService->expects($this->never())->method('importFromJson');

		$this->assertFailure(
			$this->controller->inbox('ghost'), CacheActorDoesNotExistException::class, Http::STATUS_NOT_FOUND
		);
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

	/**
	 * The status is the only thing a peer reads to decide what to do with the
	 * activity it could not deliver, and Mastodon re-queues a 5xx with backoff
	 * for about two days. Every one of these used to be a 500, so refusing a
	 * delivery — blocking an instance most of all — multiplied its traffic
	 * instead of ending it.
	 *
	 * @return iterable<string, array{Exception, int}>
	 */
	public static function inboxRejections(): iterable {
		yield 'blocked instance' => [new UnauthorizedFediverseException('blocked'), Http::STATUS_FORBIDDEN];
		yield 'bad signature' => [new SignatureException('does not verify'), Http::STATUS_UNAUTHORIZED];
		yield 'incomplete signature header' => [new MalformedArrayException('keyId'), Http::STATUS_UNAUTHORIZED];
		yield 'origin mismatch' => [new InvalidOriginException('signed by someone else'), Http::STATUS_UNAUTHORIZED];
		yield 'unparseable date' => [new DateTimeException('not a date'), Http::STATUS_BAD_REQUEST];
		yield 'key host unreachable' => [new RequestNetworkException('timeout'), Http::STATUS_SERVICE_UNAVAILABLE];
		yield 'key fetch in backoff' => [
			new SignatureException('attempted too recently', Http::STATUS_SERVICE_UNAVAILABLE),
			Http::STATUS_SERVICE_UNAVAILABLE,
		];
		yield 'a fault of our own' => [new SocialAppConfigException('no url'), Http::STATUS_INTERNAL_SERVER_ERROR];
	}

	/** @dataProvider inboxRejections */
	public function testARejectedDeliveryAnswersWhatTheRejectionActuallyIs(Exception $e, int $status): void {
		$this->signatureService->method('checkRequest')->willThrowException($e);

		$this->assertFailure($this->controller->sharedInbox(), get_class($e), $status);
	}

	public function testAMalformedBodyIsRefusedOnceInsteadOfRedelivered(): void {
		$this->signedRequestFrom('https://remote.example');
		$this->importService->method('importFromJson')
			->willThrowException(new ActivityPubFormatException('not json'));

		$this->assertFailure(
			$this->controller->sharedInbox(), ActivityPubFormatException::class, Http::STATUS_BAD_REQUEST
		);
	}

	public function testTheUserInboxRefusesABlockedInstanceWithoutInvitingARetry(): void {
		$this->signedRequestFrom('https://blocked.example');
		$this->fediverseService->method('authorized')
			->willThrowException(new UnauthorizedFediverseException('blocked'));

		$this->assertFailure(
			$this->controller->inbox('alice'), UnauthorizedFediverseException::class, Http::STATUS_FORBIDDEN
		);
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

		$this->assertFailure(
			$this->controller->outbox('ghost'), CacheActorDoesNotExistException::class,
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}

	public function testFeaturedServesThePinnedPostsAsAnOrderedCollection(): void {
		$actor = new Person();
		$actor->setId('https://cloud.example/@alice');
		$actor->setFeatured('https://cloud.example/@alice/collections/featured');
		$this->localActor('alice', $actor);

		$first = new Note();
		$first->setId('https://cloud.example/@alice/notes/2');
		$second = new Note();
		$second->setId('https://cloud.example/@alice/notes/1');
		$this->pinService->method('getPinnedPosts')->with('https://cloud.example/@alice')
			->willReturn([$first, $second]);

		$response = $this->controller->featured('alice');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		/** @var OrderedCollection $collection */
		$collection = $response->getData();
		$this->assertInstanceOf(OrderedCollection::class, $collection);
		$this->assertSame('https://cloud.example/@alice/collections/featured', $collection->getId());
		$this->assertSame(2, $collection->getTotalItems());
		$this->assertSame(
			['https://cloud.example/@alice/notes/2', 'https://cloud.example/@alice/notes/1'],
			array_column($collection->getOrderedItems(), 'id'),
			'the posts themselves are inlined, newest pin first'
		);
	}

	public function testFeaturedAlwaysServesActivityPubEvenToBrowsers(): void {
		// unlike followers/following there is no public page to fall back to
		$this->acceptHeader('text/html');
		$actor = new Person();
		$actor->setId('https://cloud.example/@alice');
		$this->localActor('alice', $actor);
		$this->pinService->method('getPinnedPosts')->willReturn([]);

		$this->assertSame(self::LD_JSON, $this->controller->featured('alice')->getHeaders()['Content-Type']);
	}

	public function testFeaturedOfUnknownUserIsANotFound(): void {
		$this->cacheActorService->method('getFromLocalAccount')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertFailure(
			$this->controller->featured('ghost'), CacheActorDoesNotExistException::class, Http::STATUS_NOT_FOUND
		);
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

	/**
	 * `?page=1` used to answer with the collection again, whose `first` pointed
	 * at itself: a consumer following it looped or gave up, and nobody could
	 * enumerate a local actor's followers.
	 */
	public function testFollowersWithAPageParameterReturnsARealPage(): void {
		$this->acceptHeader('application/activity+json');
		$actor = $this->localActor('alice');
		$page = new OrderedCollectionPage();
		$this->followService->expects($this->once())
			->method('getFollowersPage')
			->with($actor, 2)
			->willReturn($page);
		$this->followService->expects($this->never())->method('getFollowersCollection');

		$this->assertActivityPubResponse($this->controller->followers('alice', '2'), $page);
	}

	/** `?page=true` is how Mastodon asks for the first page. */
	public function testAPageParameterOfTrueMeansTheFirstPage(): void {
		$this->acceptHeader('application/activity+json');
		$actor = $this->localActor('alice');
		$page = new OrderedCollectionPage();
		$this->followService->expects($this->once())
			->method('getFollowersPage')
			->with($actor, 1)
			->willReturn($page);

		$this->assertActivityPubResponse($this->controller->followers('alice', 'true'), $page);
	}

	/** @return iterable<string, array{string}> */
	public static function unusablePageParameters(): iterable {
		yield 'absent' => [''];
		yield 'zero' => ['0'];
		yield 'negative' => ['-1'];
		yield 'not a number' => ['second'];
		yield 'an expression' => ['1 OR 1'];
	}

	/** @dataProvider unusablePageParameters */
	public function testAnUnusablePageParameterServesTheCollectionItself(string $page): void {
		$this->acceptHeader('application/activity+json');
		$actor = $this->localActor('alice');
		$collection = new OrderedCollection();
		$this->followService->method('getFollowersCollection')->with($actor)->willReturn($collection);
		$this->followService->expects($this->never())->method('getFollowersPage');

		$this->assertActivityPubResponse($this->controller->followers('alice', $page), $collection);
	}

	public function testFollowingWithAPageParameterReturnsARealPage(): void {
		$this->acceptHeader('application/activity+json');
		$actor = $this->localActor('alice');
		$page = new OrderedCollectionPage();
		$this->followService->expects($this->once())
			->method('getFollowingPage')
			->with($actor, 3)
			->willReturn($page);

		$this->assertActivityPubResponse($this->controller->following('alice', '3'), $page);
	}

	public function testOutboxWithAPageParameterReturnsTheCreateActivitiesOfThatPage(): void {
		$actor = new Person();
		$actor->setId('https://cloud.example/@alice');
		$actor->setOutbox('https://cloud.example/@alice/outbox');
		$this->localActor('alice', $actor);

		$note = new Note();
		$note->setId('https://cloud.example/@alice/notes/1');
		$note->setAttributedTo('https://cloud.example/@alice');
		$note->setContent('<p>hello</p>');
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$this->streamRequest->expects($this->once())
			->method('getPublicByAuthor')
			->with('https://cloud.example/@alice', OrderedCollection::PAGE_SIZE, 0)
			->willReturn([$note]);
		$this->streamService->expects($this->never())->method('getOutboxCollection');

		$response = $this->controller->outbox('alice', '1');
		/** @var OrderedCollectionPage $page */
		$page = $response->getData();

		$this->assertInstanceOf(OrderedCollectionPage::class, $page);
		$this->assertSame('https://cloud.example/@alice/outbox?page=1', $page->getId());
		$this->assertSame('https://cloud.example/@alice/outbox', $page->getPartOf());
		$this->assertSame('', $page->getNext(), 'a page that is not full is the last one');
		$this->assertSame('', $page->getPrev());

		$items = json_decode(json_encode($page), true)['orderedItems'];
		$this->assertCount(1, $items);
		$this->assertSame('Create', $items[0]['type']);
		$this->assertSame('https://cloud.example/@alice/notes/1/activity', $items[0]['id']);
		$this->assertSame('https://cloud.example/@alice', $items[0]['actor']);
		$this->assertSame('https://cloud.example/@alice/notes/1', $items[0]['object']['id']);
		$this->assertSame('<p>hello</p>', $items[0]['object']['content']);
		$this->assertSame(ACore::CONTEXT_PUBLIC, $items[0]['to']);
		$this->assertArrayNotHasKey(
			'@context', $items[0], 'an activity nested inside a page is not a document root'
		);
		$this->assertArrayNotHasKey('@context', $items[0]['object']);
	}

	public function testAFullOutboxPagePointsAtTheNextOne(): void {
		$actor = new Person();
		$actor->setId('https://cloud.example/@alice');
		$actor->setOutbox('https://cloud.example/@alice/outbox');
		$this->localActor('alice', $actor);

		$posts = [];
		for ($i = 0; $i < OrderedCollection::PAGE_SIZE; $i++) {
			$note = new Note();
			$note->setId('https://cloud.example/@alice/notes/' . $i);
			$note->setAttributedTo('https://cloud.example/@alice');
			$posts[] = $note;
		}
		$this->streamRequest->method('getPublicByAuthor')->willReturn($posts);

		/** @var OrderedCollectionPage $page */
		$page = $this->controller->outbox('alice', '2')->getData();

		$this->assertSame('https://cloud.example/@alice/outbox?page=3', $page->getNext());
		$this->assertSame('https://cloud.example/@alice/outbox?page=1', $page->getPrev());
	}

	public function testFollowersOfUnknownUserFails(): void {
		$this->acceptHeader('application/activity+json');
		$this->cacheActorService->method('getFromLocalAccount')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertFailure(
			$this->controller->followers('ghost'), CacheActorDoesNotExistException::class,
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}

	// displayPost()

	/** @return iterable<string, array{string, string}> */
	public static function reservedTokens(): iterable {
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
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $data) use (&$states): void {
				$states['social'][$key] = $data;
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
		$this->initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $data) use (&$keys): void {
				$keys[] = $key;
			});

		$response = $this->controller->displayPost('alice', 'missing');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(['serverData'], $keys);
	}

	// instanceActor()

	/**
	 * Every outbound signed fetch names this document's URL as its `keyId`; a
	 * peer running authorized-fetch dereferences it before answering, so a
	 * signature naming a key nobody can find is worse than no signature.
	 */
	public function testTheInstanceActorIsServedAsActivityPub(): void {
		$actor = new InstanceActor();
		$actor->setId(self::SOCIAL_URL . 'actor');
		$this->instanceActorService->method('getActor')->willReturn($actor);

		$response = $this->controller->instanceActor();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::LD_JSON, $response->getHeaders()['Content-Type']);
		$this->assertSame($actor, $response->getData());
	}

	/** An instance that cannot produce one says so rather than half-serving it. */
	public function testAnInstanceActorThatCannotBeBuiltIsA404(): void {
		$this->instanceActorService->method('getActor')
			->willThrowException(new SocialAppConfigException('no cloud address yet'));

		$response = $this->controller->instanceActor();

		$this->assertFailure($response, SocialAppConfigException::class, Http::STATUS_NOT_FOUND);
	}

	// replies()

	public function testRepliesServesTheCollectionOfAPost(): void {
		$post = $this->quotablePost();
		$collection = new OrderedCollection();
		$this->streamService->expects($this->once())
			->method('getRepliesCollection')
			->with($this->identicalTo($post))
			->willReturn($collection);

		$response = $this->controller->replies('alice', 'abc123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::LD_JSON, $response->getHeaders()['Content-Type']);
		$this->assertSame($collection, $response->getData());
	}

	/** @return iterable<string, array{string, int}> */
	public static function requestedReplyPages(): iterable {
		yield 'a numbered page' => ['2', 2];
		yield "Mastodon's page=true, which means the first" => ['true', 1];
	}

	/** @dataProvider requestedReplyPages */
	public function testRepliesServesTheRequestedPage(string $page, int $expected): void {
		$this->quotablePost();
		$collectionPage = new OrderedCollectionPage();
		$this->streamService->expects($this->once())
			->method('getRepliesPage')
			->with($this->anything(), $expected)
			->willReturn($collectionPage);

		$response = $this->controller->replies('alice', 'abc123', $page);

		$this->assertSame($collectionPage, $response->getData());
	}

	/** Somebody else's replies live under an id this instance does not own. */
	public function testRepliesOfARemotePostIsA404(): void {
		$post = $this->quotablePost();
		$post->setLocal(false);
		$this->streamService->expects($this->never())->method('getRepliesCollection');

		$response = $this->controller->replies('alice', 'abc123');

		$this->assertFailure($response, ItemUnknownException::class, Http::STATUS_NOT_FOUND);
	}

	public function testRepliesOfAnUnknownPostIsA404(): void {
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$response = $this->controller->replies('alice', 'missing');

		$this->assertFailure($response, StreamNotFoundException::class, Http::STATUS_NOT_FOUND);
		$this->assertSame(self::SOCIAL_URL . '@alice/missing', $response->getData()['stream']);
	}

	// displayQuoteAuthorization()

	private function quotablePost(string $token = 'abc123'): Note {
		$post = new Note();
		$post->setId(self::SOCIAL_URL . '@alice/' . $token);
		$post->setAttributedTo(self::SOCIAL_URL . '@alice');
		$post->setLocal(true);
		$post->setVisibility(Stream::TYPE_PUBLIC);
		$this->streamService->method('getStreamById')->with($post->getId())->willReturn($post);

		return $post;
	}

	public function testQuoteAuthorizationNamesTheAuthorAndBothPosts(): void {
		$quoted = $this->quotablePost();
		$quoting = 'https://remote.example/users/bob/statuses/7';
		$stamp = QuoteRequestInterface::stamp($quoting);

		$response = $this->controller->displayQuoteAuthorization('alice', 'abc123', $stamp);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::LD_JSON, $response->getHeaders()['Content-Type']);
		$authorization = $response->getData();
		$this->assertInstanceOf(QuoteAuthorization::class, $authorization);
		$this->assertSame($quoted->getAttributedTo(), $authorization->getAttributedTo());
		$this->assertSame($quoting, $authorization->getInteractingObject());
		$this->assertSame($quoted->getId(), $authorization->getInteractionTarget());
	}

	/**
	 * The document has to be served from the URI it claims, or a verifier that
	 * compares the two treats it as somebody else's approval.
	 */
	public function testQuoteAuthorizationIdIsTheUrlItWasFetchedFrom(): void {
		$this->quotablePost();
		$stamp = QuoteRequestInterface::stamp('https://remote.example/users/bob/statuses/7');

		$response = $this->controller->displayQuoteAuthorization('alice', 'abc123', $stamp);

		$this->assertSame(
			self::SOCIAL_URL . '@alice/abc123/quote_authorizations/' . $stamp,
			$response->getData()->getId()
		);
	}

	/**
	 * The `Accept` and this endpoint have to agree about what the URI in the
	 * `Accept` means, or every approval we grant is a dead link.
	 */
	public function testTheApprovalUriWeFederateIsTheOneThisEndpointAnswers(): void {
		$quoted = $this->quotablePost();
		$quoting = 'https://remote.example/users/bob/statuses/7';
		$granted = $quoted->getId() . '/quote_authorizations/' . QuoteRequestInterface::stamp($quoting);

		$path = substr($granted, strlen(self::SOCIAL_URL));
		$this->assertSame(1, preg_match('#^@(.+?)/(.+?)/quote_authorizations/(.+)$#', $path, $m));

		$response = $this->controller->displayQuoteAuthorization($m[1], $m[2], $m[3]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($granted, $response->getData()->getId());
		$this->assertSame($quoting, $response->getData()->getInteractingObject());
	}

	public function testQuoteAuthorizationOfAnUnknownPostIs404(): void {
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$response = $this->controller->displayQuoteAuthorization('alice', 'missing', QuoteRequestInterface::stamp('https://remote.example/1'));

		$this->assertFailure($response, StreamNotFoundException::class, Http::STATUS_NOT_FOUND);
		$this->assertSame(self::SOCIAL_URL . '@alice/missing', $response->getData()['stream']);
	}

	/**
	 * A post narrowed after the approval was granted stops being quotable, and
	 * the approval has to stop with it — a verifier that re-checks is how the
	 * author's change of mind reaches the other server.
	 */
	public function testQuoteAuthorizationIsWithdrawnOnceThePostIsNoLongerQuotable(): void {
		$post = $this->quotablePost();
		$post->setVisibility(Stream::TYPE_FOLLOWERS);

		$response = $this->controller->displayQuoteAuthorization('alice', 'abc123', QuoteRequestInterface::stamp('https://remote.example/1'));

		$this->assertFailure($response, ItemUnknownException::class, Http::STATUS_NOT_FOUND);
	}

	/** Somebody else's post is not ours to grant permission over. */
	public function testQuoteAuthorizationIsNotServedForARemotePost(): void {
		$post = $this->quotablePost();
		$post->setLocal(false);

		$response = $this->controller->displayQuoteAuthorization('alice', 'abc123', QuoteRequestInterface::stamp('https://remote.example/1'));

		$this->assertFailure($response, ItemUnknownException::class, Http::STATUS_NOT_FOUND);
	}

	/** @return iterable<string, array{string}> */
	public static function unservableStamps(): iterable {
		yield 'not base64 at all' => ['not a stamp'];
		yield 'padded, which we never emit' => [rtrim(strtr(base64_encode('https://remote.example/1'), '+/', '-_'), '=') . '='];
		yield 'base64 of something that is not an address' => [rtrim(strtr(base64_encode('../../admin'), '+/', '-_'), '=')];
		// decodes to exactly the same URI as `aHR0cHM6Ly9yZW1vdGUuZXhhbXBsZS8xMg`,
		// in the unused low bits of the last character: base64 spells some byte
		// strings more than one way, and only one of those spellings is a URI
		// this server ever handed out
		yield 'a second spelling of a stamp we do emit' => ['aHR0cHM6Ly9yZW1vdGUuZXhhbXBsZS8xMh'];
		yield 'empty' => [''];
	}

	/** @dataProvider unservableStamps */
	public function testAStampWeNeverIssuedGetsNoApproval(string $stamp): void {
		$this->quotablePost();

		$response = $this->controller->displayQuoteAuthorization('alice', 'abc123', $stamp);

		$this->assertFailure($response, ItemUnknownException::class, Http::STATUS_NOT_FOUND);
	}

	/**
	 * The approval is a public statement about a public post; reading it must
	 * not depend on, or reveal, who is asking.
	 */
	public function testQuoteAuthorizationIsServedWithoutAViewer(): void {
		$this->quotablePost();
		$this->streamService->expects($this->never())->method('setViewer');

		$response = $this->controller->displayQuoteAuthorization('alice', 'abc123', QuoteRequestInterface::stamp('https://remote.example/1'));

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTheApprovalSerialisesAsAQuoteAuthorizationDocument(): void {
		$this->quotablePost();
		$quoting = 'https://remote.example/users/bob/statuses/7';

		$exported = $this->controller
			->displayQuoteAuthorization('alice', 'abc123', QuoteRequestInterface::stamp($quoting))
			->getData()
			->exportAsActivityPub();

		$this->assertSame('QuoteAuthorization', $exported['type']);
		$this->assertSame(self::SOCIAL_URL . '@alice', $exported['attributedTo']);
		$this->assertSame($quoting, $exported['interactingObject']);
		$this->assertSame(self::SOCIAL_URL . '@alice/abc123', $exported['interactionTarget']);
		$this->assertContains(
			'QuoteAuthorization',
			array_keys(end($exported['@context'])),
			'the document names a type no reader can resolve without the FEP-044f terms'
		);
	}

	// authorized fetch, and secure mode

	/**
	 * Signature verification ran on inbox POSTs only, so this instance could
	 * not tell one remote reader from another: every ActivityPub GET served
	 * what an anonymous reader gets, and a follower on another server saw a
	 * profile with nothing on it.
	 */
	public function testASignedFetchIsTheViewerAPostIsReadAs(): void {
		$this->acceptHeader('application/activity+json');
		$this->accountService->method('getCurrentViewer')
			->willThrowException(new AccountDoesNotExistException());
		$this->signedReader = $this->createMock(Person::class);
		$this->streamService->expects($this->once())->method('setViewer')->with($this->signedReader);
		$stream = $this->createMock(Stream::class);
		$this->streamService->method('getStreamById')->willReturn($stream);

		$this->assertActivityPubResponse($this->controller->displayPost('alice', 'abc123'), $stream);
	}

	/** An unsigned GET is the ordinary case and gets what it always got. */
	public function testAnUnsignedFetchReadsAsNobody(): void {
		$this->acceptHeader('application/activity+json');
		$this->accountService->method('getCurrentViewer')
			->willThrowException(new AccountDoesNotExistException());
		$this->streamService->expects($this->never())->method('setViewer');
		$this->streamService->method('getStreamById')->willReturn($this->createMock(Stream::class));

		$this->controller->displayPost('alice', 'abc123');
	}

	/** A local session wins: the person at the keyboard is who is asking. */
	public function testALocalViewerIsNotReplacedByASignedFetch(): void {
		$this->acceptHeader('application/activity+json');
		$viewer = $this->createMock(Person::class);
		$this->accountService->method('getCurrentViewer')->willReturn($viewer);
		$this->signedReader = $this->createMock(Person::class);
		$this->streamService->expects($this->once())->method('setViewer')->with($viewer);
		$this->streamService->method('getStreamById')->willReturn($this->createMock(Stream::class));

		$this->controller->displayPost('alice', 'abc123');
	}

	/**
	 * Secure mode is off by default, because turning it on makes this instance
	 * invisible to every peer that does not sign its fetches.
	 */
	public function testWithoutSecureModeAnUnsignedActorFetchIsAnswered(): void {
		$this->acceptHeader('application/activity+json');
		$actor = $this->localActor('alice');

		$this->assertActivityPubResponse($this->controller->actor('alice'), $actor);
	}

	public function testInSecureModeAnUnsignedActorFetchIsRefused(): void {
		$this->acceptHeader('application/activity+json');
		$this->secureMode = true;
		$this->localActor('alice');

		$response = $this->controller->actor('alice');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testInSecureModeASignedActorFetchIsAnswered(): void {
		$this->acceptHeader('application/activity+json');
		$this->secureMode = true;
		$this->signedReader = $this->createMock(Person::class);
		$actor = $this->localActor('alice');

		$this->assertActivityPubResponse($this->controller->actor('alice'), $actor);
	}

	public function testInSecureModeAnUnsignedPostFetchIsRefused(): void {
		$this->acceptHeader('application/activity+json');
		$this->secureMode = true;
		$this->streamService->expects($this->never())->method('getStreamById');

		$response = $this->controller->displayPost('alice', 'abc123');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/** A browser asking for the page is not making an ActivityPub fetch. */
	public function testSecureModeDoesNotRefuseABrowser(): void {
		$this->acceptHeader('text/html');
		$this->secureMode = true;
		$this->cacheActorService->method('getFromLocalAccount')
			->willReturn($this->createMock(Person::class));
		$this->socialPubController->method('actor')
			->willReturn(new \OCP\AppFramework\Http\TemplateResponse('social', 'main'));

		$this->assertNotSame(Http::STATUS_UNAUTHORIZED, $this->controller->actor('alice')->getStatus());
	}
}
