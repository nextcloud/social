<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\AP;
use OCA\Social\Controller\ApiController;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Instance;
use OCA\Social\Model\Post;
use OCA\Social\Model\Relationship;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActionService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\RelationshipService;
use OCA\Social\Service\ReportService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ApiControllerTest extends TestCase {
	private const REVOKED = 'the access_token was revoked';

	/** @var IRequest&MockObject */
	private $request;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var IUserSession&MockObject */
	private $userSession;
	/** @var InstanceService&MockObject */
	private $instanceService;
	/** @var ClientService&MockObject */
	private $clientService;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var CacheDocumentService&MockObject */
	private $cacheDocumentService;
	/** @var DocumentService&MockObject */
	private $documentService;
	/** @var FollowService&MockObject */
	private $followService;
	/** @var RelationshipService&MockObject */
	private $relationshipService;
	/** @var StreamService&MockObject */
	private $streamService;
	/** @var ActionService&MockObject */
	private $actionService;
	/** @var PostService&MockObject */
	private $postService;
	/** @var ReportService&MockObject */
	private $reportService;
	/** @var ConfigService&MockObject */
	private $configService;
	/** @var CurlService&MockObject */
	private $curlService;

	private array $filesBackup;
	/** the value getParam('_route') hands the controller, per test */
	private string $route = '';
	/** what passesCSRFCheck() reports, per test */
	private bool $csrf = true;
	/** the value getParam('description') hands the controller, per test */
	private string $description = '';

	protected function setUp(): void {
		$this->filesBackup = $_FILES;
		$_FILES = [];

		$this->request = $this->createMock(IRequest::class);
		// the app's own frontend sends the requesttoken header on every call
		$this->csrf = true;
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->route = '';
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => match ($key) {
				'_route' => $this->route,
				'description' => ($this->description === '') ? $default : $this->description,
				default => $default,
			}
		);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->instanceService = $this->createMock(InstanceService::class);
		$this->clientService = $this->createMock(ClientService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->relationshipService = $this->createMock(RelationshipService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->actionService = $this->createMock(ActionService::class);
		$this->postService = $this->createMock(PostService::class);
		$this->reportService = $this->createMock(ReportService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->curlService = $this->createMock(CurlService::class);

		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		$_FILES = $this->filesBackup;
		AP::$activityPub = null;
		\OC::$server->reset();
	}

	private function controller(string $authorization = ''): ApiController {
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'Authorization' ? $authorization : ''
		);

		return new ApiController(
			$this->request,
			$this->urlGenerator,
			$this->userSession,
			new NullLogger(),
			$this->instanceService,
			$this->clientService,
			$this->accountService,
			$this->cacheActorService,
			$this->cacheDocumentService,
			$this->documentService,
			$this->followService,
			$this->relationshipService,
			$this->streamService,
			$this->actionService,
			$this->postService,
			$this->reportService,
			$this->configService,
			$this->curlService
		);
	}

	/**
	 * A session user "alice" whose actor is cached: what initViewer() needs.
	 * @return Person&MockObject
	 */
	private function loggedInAs(string $uid = 'alice'): Person {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		$account = $this->createMock(Person::class);
		$account->method('getPreferredUsername')->willReturn($uid);
		$account->method('getId')->willReturn('https://cloud.example/apps/social/@' . $uid);
		$this->accountService->method('getActorFromUserId')->with($uid, true)->willReturn($account);

		$viewer = $this->createMock(Person::class);
		$viewer->method('getPreferredUsername')->willReturn($uid);
		$viewer->method('getId')->willReturn('https://cloud.example/apps/social/@' . $uid);
		$this->cacheActorService->method('getFromLocalAccount')->with($uid)->willReturn($viewer);

		return $viewer;
	}

	private function assertUnauthorized(DataResponse $response, string $error = self::REVOKED): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => $error], $response->getData());
	}

	/**
	 * Captures the ProbeOptions handed to StreamService::getTimeline().
	 */
	private function captureTimelineOptions(array $posts = []): \Closure {
		$captured = null;
		$this->streamService->method('getTimeline')
			->willReturnCallback(function (ProbeOptions $options) use (&$captured, $posts): array {
				$captured = $options;

				return $posts;
			});

		return function () use (&$captured): ProbeOptions {
			$this->assertInstanceOf(ProbeOptions::class, $captured, 'getTimeline() was not called');

			return $captured;
		};
	}

	// credentials

	public function testAppsCredentialsRequiresAViewer(): void {
		$this->assertUnauthorized($this->controller()->appsCredentials());
	}

	public function testAppsCredentialsForSessionUserIsTheBuiltInClient(): void {
		$this->loggedInAs();

		$response = $this->controller()->appsCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['name' => 'Nextcloud Social', 'website' => 'https://github.com/nextcloud/social/'],
			$response->getData()
		);
	}

	public function testAppsCredentialsForBearerTokenDescribesTheOAuthClient(): void {
		$this->route = 'social.Api.appsCredentials';
		$client = new SocialClient();
		$client->setAppName('Tusky')->setAppWebsite('https://tusky.app')->setAuthUserId('alice');
		$this->clientService->method('getFromToken')->with('s3cret')->willReturn($client);
		$this->userSession->method('getUser')->willReturn(null);

		$account = $this->createMock(Person::class);
		$account->method('getPreferredUsername')->willReturn('alice');
		$this->accountService->method('getActorFromUserId')->with('alice', true)->willReturn($account);
		$this->cacheActorService->method('getFromLocalAccount')->with('alice')->willReturn($this->createMock(Person::class));

		$response = $this->controller('Bearer s3cret')->appsCredentials();

		$this->assertSame(['name' => 'Tusky', 'website' => 'https://tusky.app'], $response->getData());
	}

	/** A bearer client for alice, granted the given scopes. */
	private function bearerFor(array $scopes, string $uid = 'alice'): void {
		$client = new SocialClient();
		$client->setAppName('Tusky')->setAuthUserId($uid)->setAuthScopes($scopes);
		$this->clientService->method('getFromToken')->with('s3cret')->willReturn($client);

		$account = $this->createMock(Person::class);
		$account->method('getPreferredUsername')->willReturn($uid);
		$account->method('getId')->willReturn('https://cloud.example/apps/social/@' . $uid);
		$this->accountService->method('getActorFromUserId')->with($uid, true)->willReturn($account);

		$viewer = $this->createMock(Person::class);
		$viewer->method('getPreferredUsername')->willReturn($uid);
		$viewer->method('getId')->willReturn('https://cloud.example/apps/social/@' . $uid);
		$this->cacheActorService->method('getFromLocalAccount')->with($uid)->willReturn($viewer);
	}

	// token scopes

	public function testAWriteRouteRefusesAReadOnlyToken(): void {
		$this->route = 'social.Api.statusNew';
		$this->bearerFor(['read']);
		$this->postService->expects($this->never())->method('createPost');

		$response = $this->controller('Bearer s3cret')->statusNew();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'token scope does not allow this request (needs write)'],
			$response->getData()
		);
	}

	public function testABlockRouteRefusesAReadOnlyToken(): void {
		$this->route = 'social.Api.accountMute';
		$this->bearerFor(['read']);

		$this->assertUnauthorized(
			$this->controller('Bearer s3cret')->accountMute('42'),
			'token scope does not allow this request (needs follow or write)'
		);
	}

	public function testAReadRouteRefusesAScopelessToken(): void {
		$this->route = 'social.Api.verifyCredentials';
		$this->bearerFor([]);

		$this->assertUnauthorized(
			$this->controller('Bearer s3cret')->verifyCredentials(),
			'token scope does not allow this request (needs read)'
		);
	}

	public function testAGranularWriteScopeSatisfiesAWriteRoute(): void {
		$this->route = 'social.Api.statusNew';
		$this->bearerFor(['read', 'write:statuses']);
		$this->request->method('getParams')->willReturn(['status' => 'hi']);

		$activity = $this->createMock(ACore::class);
		$activity->method('getObjectId')->willReturn('https://cloud.example/apps/social/@alice/n1');
		$this->postService->method('createPost')->willReturn($activity);
		$this->streamService->method('getStreamById')->willReturn($this->createMock(Stream::class));

		$this->assertSame(Http::STATUS_OK, $this->controller('Bearer s3cret')->statusNew()->getStatus());
	}

	public function testABearerTokenIsScopedEvenWhenASessionExists(): void {
		// the token's grant must not silently widen to the cookie's full access
		$this->route = 'social.Api.statusNew';
		$this->loggedInAs();
		$this->bearerFor(['read']);
		$this->postService->expects($this->never())->method('createPost');

		$response = $this->controller('Bearer s3cret')->statusNew();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'token scope does not allow this request (needs write)'],
			$response->getData()
		);
	}

	public function testASessionWithoutACsrfTokenIsRefused(): void {
		$this->csrf = false;
		$this->loggedInAs();

		$this->assertUnauthorized($this->controller()->verifyCredentials());
	}

	public function testNonBearerAuthorizationIsIgnored(): void {
		$this->clientService->expects($this->never())->method('getFromToken');

		$this->assertUnauthorized($this->controller('Basic abc')->appsCredentials());
	}

	public function testRevokedBearerTokenIsUnauthorized(): void {
		$this->clientService->method('getFromToken')->willThrowException(new ClientNotFoundException());

		$this->assertUnauthorized($this->controller('Bearer gone')->verifyCredentials());
	}

	public function testVerifyCredentialsReturnsTheViewerInLocalFormat(): void {
		$viewer = $this->loggedInAs();
		$viewer->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->streamService->expects($this->once())->method('setViewer')->with($viewer);
		$this->followService->expects($this->once())->method('setViewer')->with($viewer);
		$this->cacheActorService->expects($this->once())->method('setViewer')->with($viewer);

		$response = $this->controller()->verifyCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($viewer, $response->getData());
	}

	public function testVerifyCredentialsIsUnauthorizedForAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertUnauthorized($this->controller()->verifyCredentials());
	}

	public function testViewerIsCachedOnDemandWhenMissingFromCache(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$account = $this->createMock(Person::class);
		$account->method('getPreferredUsername')->willReturn('alice');
		$this->accountService->method('getActorFromUserId')->willReturn($account);

		$viewer = $this->createMock(Person::class);
		$this->cacheActorService->method('getFromLocalAccount')->with('alice')
			->will($this->onConsecutiveCalls($this->throwException(new CacheActorDoesNotExistException()), $viewer));
		$this->accountService->expects($this->once())->method('cacheLocalActorByUsername')->with('alice');

		$this->assertSame($viewer, $this->controller()->verifyCredentials()->getData());
	}

	public function testSavedSearchesIsEmptyForAViewerAndUnauthorizedOtherwise(): void {
		$this->assertUnauthorized($this->controller()->savedSearches());

		$this->loggedInAs();
		$response = $this->controller()->savedSearches();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	// instance / emojis

	public function testInstanceReturnsTheLocalInstanceInLocalFormat(): void {
		$instance = new Instance();
		$this->instanceService->expects($this->once())->method('getLocal')->with(Stream::FORMAT_LOCAL)->willReturn($instance);

		$response = $this->controller()->instance();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($instance, $response->getData());
	}

	public function testCustomEmojisIsAnEmptyList(): void {
		$response = $this->controller()->customEmojis();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	// timelines

	/** @return iterable<string, array{string}> */
	public function supportedTimelines(): iterable {
		yield 'home' => ['home'];
		yield 'account' => ['account'];
		yield 'public' => ['public'];
		yield 'direct' => ['direct'];
		yield 'favourites' => ['favourites'];
		yield 'case-insensitive' => ['HOME'];
	}

	/** @dataProvider supportedTimelines */
	public function testTimelinesProbesTheRequestedTimelineWithPagination(string $timeline): void {
		$this->loggedInAs();
		$posts = [$this->createMock(Stream::class)];
		$options = $this->captureTimelineOptions($posts);

		$response = $this->controller()->timelines($timeline, true, 15, 300, 100, 200);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($posts, $response->getData());
		$probe = $options();
		$this->assertSame(strtolower($timeline), $probe->getProbe());
		$this->assertTrue($probe->isLocal());
		$this->assertSame(15, $probe->getLimit());
		$this->assertSame(300, $probe->getMaxId());
		$this->assertSame(100, $probe->getMinId());
		$this->assertSame(200, $probe->getSince());
		$this->assertSame(ACore::FORMAT_LOCAL, $probe->getFormat());
	}

	/** @return iterable<string, array{string}> */
	public function unsupportedTimelines(): iterable {
		yield 'trending' => ['trending'];
		yield 'notifications' => ['notifications'];
		yield 'hashtag' => ['hashtag'];
		yield 'empty' => [''];
	}

	/** @dataProvider unsupportedTimelines */
	public function testTimelinesRejectsUnknownTimelineNames(string $timeline): void {
		$this->loggedInAs();
		$this->streamService->expects($this->never())->method('getTimeline');

		$this->assertUnauthorized($this->controller()->timelines($timeline), 'unknown timeline');
	}

	public function testTimelinesRequiresAViewer(): void {
		$this->streamService->expects($this->never())->method('getTimeline');

		$this->assertUnauthorized($this->controller()->timelines('home'));
	}

	public function testTimelinesReportsServiceFailures(): void {
		$this->loggedInAs();
		$this->streamService->method('getTimeline')->willThrowException(new \RuntimeException('db down'));

		$this->assertUnauthorized($this->controller()->timelines('home'), 'db down');
	}

	// statuses

	public function testStatusGetReturnsTheStreamInLocalFormatEvenAnonymously(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$item = $this->createMock(Stream::class);
		$this->streamService->method('getStreamByNid')->with(42)->willReturn($item);
		$item->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);

		$response = $this->controller()->statusGet(42);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($item, $response->getData());
	}

	public function testStatusGetOfUnknownStatusIsAnError(): void {
		$this->streamService->method('getStreamByNid')->willThrowException(new StreamNotFoundException('not found'));

		$this->assertUnauthorized($this->controller()->statusGet(42), 'not found');
	}

	public function testStatusContextReturnsAncestorsAndDescendants(): void {
		$context = ['ancestors' => [], 'descendants' => [$this->createMock(Stream::class)]];
		$this->streamService->method('getContextByNid')->with(7)->willReturn($context);

		$response = $this->controller()->statusContext(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($context, $response->getData());
	}

	/** @return iterable<string, array{string}> */
	public function statusActions(): iterable {
		yield 'favourite' => ['favourite'];
		yield 'unfavourite' => ['unfavourite'];
		yield 'reblog' => ['reblog'];
		yield 'unreblog' => ['unreblog'];
	}

	/** @dataProvider statusActions */
	public function testStatusActionDispatchesToActionServiceAsTheViewersActor(string $action): void {
		$this->loggedInAs();
		$actor = $this->createMock(Person::class);
		$this->accountService->method('getActor')->with('alice')->willReturn($actor);
		$item = $this->createMock(Stream::class);
		$this->actionService->expects($this->once())->method('action')->with($actor, 12, $action)->willReturn($item);
		$item->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->streamService->expects($this->never())->method('getStreamByNid');

		$response = $this->controller()->statusAction(12, $action);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($item, $response->getData());
	}

	public function testStatusActionFallsBackToTheStatusWhenActionReturnsNothing(): void {
		$this->loggedInAs();
		$this->accountService->method('getActor')->willReturn($this->createMock(Person::class));
		$this->actionService->method('action')->willReturn(null);
		$item = $this->createMock(Stream::class);
		$this->streamService->expects($this->once())->method('getStreamByNid')->with(12)->willReturn($item);

		$this->assertSame($item, $this->controller()->statusAction(12, 'translate')->getData());
	}

	public function testStatusActionRejectsUnknownActions(): void {
		$this->loggedInAs();
		$this->accountService->method('getActor')->willReturn($this->createMock(Person::class));
		$this->actionService->method('action')->willThrowException(new InvalidActionException('unknown action'));

		$this->assertUnauthorized($this->controller()->statusAction(12, 'explode'), 'unknown action');
	}

	public function testStatusActionRequiresAViewer(): void {
		$this->actionService->expects($this->never())->method('action');

		$this->assertUnauthorized($this->controller()->statusAction(12, 'favourite'));
	}

	// statusNew / statusUpdate

	public function testStatusNewCreatesAPostFromTheFormParameters(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([
			'status' => "hello\nworld",
			'visibility' => 'unlisted',
			'in_reply_to_id' => 7,
		]);
		$parent = $this->createMock(Stream::class);
		$parent->method('getId')->willReturn('https://remote.example/notes/7');
		$this->streamService->method('getStreamByNid')->with(7)->willReturn($parent);

		$activity = $this->createMock(ACore::class);
		$activity->method('getObjectId')->willReturn('https://cloud.example/apps/social/@alice/n1');
		$created = null;
		$this->postService->expects($this->once())->method('createPost')
			->willReturnCallback(function (Post $post) use (&$created, $activity): ACore {
				$created = $post;

				return $activity;
			});
		$item = $this->createMock(Stream::class);
		$this->streamService->method('getStreamById')
			->with('https://cloud.example/apps/social/@alice/n1', true, ACore::FORMAT_LOCAL)
			->willReturn($item);

		$response = $this->controller()->statusNew();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($item, $response->getData());
		$this->assertSame("hello\nworld", $created->getContent(), 'the raw text; PostService escapes and converts newlines');
		$this->assertSame('unlisted', $created->getType());
		$this->assertSame('https://remote.example/notes/7', $created->getReplyTo());
	}

	public function testStatusNewIgnoresAMissingParent(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'hi', 'in_reply_to_id' => 99]);
		$this->streamService->method('getStreamByNid')->willThrowException(new StreamNotFoundException());
		$activity = $this->createMock(ACore::class);
		$activity->method('getObjectId')->willReturn('id');
		$created = null;
		$this->postService->method('createPost')->willReturnCallback(function (Post $post) use (&$created, $activity): ACore {
			$created = $post;

			return $activity;
		});
		$this->streamService->method('getStreamById')->willReturn($this->createMock(Stream::class));

		$this->controller()->statusNew();

		$this->assertSame('', $created->getReplyTo());
	}

	public function testStatusNewIsABadRequestForAnonymous(): void {
		$this->postService->expects($this->never())->method('createPost');

		$response = $this->controller()->statusNew();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => self::REVOKED], $response->getData());
	}

	public function testStatusUpdateEditsTheStatusWithSpoilerAndSensitivity(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn([
			'status' => "edited\ntext",
			'spoiler_text' => 'cw',
			'sensitive' => true,
		]);
		$item = $this->createMock(Stream::class);
		$this->postService->expects($this->once())->method('editPost')
			->with(5, $this->isInstanceOf(Person::class), "edited\ntext", 'cw', true)
			->willReturn($item);
		$item->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);

		$response = $this->controller()->statusUpdate(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($item, $response->getData());
	}

	public function testStatusUpdateWithoutSpoilerPassesNull(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'x']);
		$this->postService->expects($this->once())->method('editPost')
			->with(5, $this->anything(), 'x', null, false)
			->willReturn($this->createMock(Stream::class));

		$this->controller()->statusUpdate(5);
	}

	public function testStatusUpdateFailureIsABadRequest(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['status' => 'x']);
		$this->postService->method('editPost')->willThrowException(new StreamNotFoundException('gone'));

		$response = $this->controller()->statusUpdate(5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'gone'], $response->getData());
	}

	// relationships / accounts

	public function testRelationshipsAreResolvedByFollowService(): void {
		$this->loggedInAs();
		$this->followService->expects($this->once())->method('getRelationships')->with([1, 2])->willReturn(['r1', 'r2']);

		$response = $this->controller()->relationships([1, 2]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['r1', 'r2'], $response->getData());
	}

	public function testRelationshipsRequireAViewer(): void {
		$this->assertUnauthorized($this->controller()->relationships([1]));
	}

	// blocking / muting

	/**
	 * A target account resolvable by its numeric id, as resolveTargetAccount() does.
	 *
	 * @return Person&MockObject
	 */
	private function knownTarget(int $nid = 42): Person {
		$target = $this->createMock(Person::class);
		$target->method('getNid')->willReturn($nid);
		$this->cacheActorService->expects($this->once())
			->method('getFromNids')->with([$nid])->willReturn([$target]);

		return $target;
	}

	public function testAccountBlockBlocksTheResolvedAccountAndReturnsTheRelationship(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->relationshipService->expects($this->once())
			->method('block')
			->with($this->identicalTo($viewer), $this->identicalTo($target));

		$relationship = new Relationship(42);
		$this->followService->expects($this->once())
			->method('getRelationshipWith')->with($this->identicalTo($target))->willReturn($relationship);

		$response = $this->controller()->accountBlock('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($relationship, $response->getData());
	}

	public function testAccountUnblockUnblocksTheResolvedAccount(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->relationshipService->expects($this->once())
			->method('unblock')
			->with($this->identicalTo($viewer), $this->identicalTo($target));
		$this->relationshipService->expects($this->never())->method('block');

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountUnblock('42')->getStatus());
	}

	public function testAccountMuteHidesNotificationsByDefault(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->relationshipService->expects($this->once())
			->method('mute')
			->with($this->identicalTo($viewer), $this->identicalTo($target), true);

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountMute('42')->getStatus());
	}

	public function testAccountMuteCanKeepNotifications(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->relationshipService->expects($this->once())
			->method('mute')
			->with($this->identicalTo($viewer), $this->identicalTo($target), false);

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountMute('42', false)->getStatus());
	}

	public function testAccountUnmuteUnmutesTheResolvedAccount(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->relationshipService->expects($this->once())
			->method('unmute')
			->with($this->identicalTo($viewer), $this->identicalTo($target));

		$this->assertSame(Http::STATUS_OK, $this->controller()->accountUnmute('42')->getStatus());
	}

	public function testAccountBlockOfAnUnknownAccountIsAnErrorAndBlocksNothing(): void {
		$this->loggedInAs();
		$this->cacheActorService->method('getFromNids')->with([42])->willReturn([]);
		$this->cacheActorService->method('getFromId')
			->with('42')
			->willThrowException(new CacheActorDoesNotExistException('who?'));
		$this->relationshipService->expects($this->never())->method('block');

		$this->assertUnauthorized($this->controller()->accountBlock('42'), 'who?');
	}

	public function testAccountBlockRequiresAViewer(): void {
		$this->relationshipService->expects($this->never())->method('block');

		$this->assertUnauthorized($this->controller()->accountBlock('42'));
	}

	public function testBlocksListTheBlockedAccountsForLocalExport(): void {
		$viewer = $this->loggedInAs();
		$blocked = $this->createMock(Person::class);
		$blocked->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->relationshipService->expects($this->once())
			->method('getRelated')
			->with($this->identicalTo($viewer), ActorRelation::TYPE_BLOCK, 40)
			->willReturn([$blocked]);

		$response = $this->controller()->blocks();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$blocked], $response->getData());
	}

	public function testMutesListTheMutedAccounts(): void {
		$viewer = $this->loggedInAs();
		$muted = $this->createMock(Person::class);
		$this->relationshipService->expects($this->once())
			->method('getRelated')
			->with($this->identicalTo($viewer), ActorRelation::TYPE_MUTE, 40)
			->willReturn([$muted]);

		$response = $this->controller()->mutes();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$muted], $response->getData());
	}

	public function testBlocksRequireAViewer(): void {
		$this->relationshipService->expects($this->never())->method('getRelated');

		$this->assertUnauthorized($this->controller()->blocks());
	}

	// follow requests

	public function testFollowRequestsListThePendingAccountsForLocalExport(): void {
		$this->loggedInAs();
		$pending = $this->createMock(Person::class);
		$pending->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->followService->expects($this->once())
			->method('getPendingRequests')->willReturn([$pending]);

		$response = $this->controller()->followRequests();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([$pending], $response->getData());
	}

	public function testFollowRequestsRequireAViewer(): void {
		$this->followService->expects($this->never())->method('getPendingRequests');

		$this->assertUnauthorized($this->controller()->followRequests());
	}

	public function testFollowRequestAuthorizeConfirmsAndReturnsTheRelationship(): void {
		$this->loggedInAs();
		$target = $this->knownTarget();
		$this->followService->expects($this->once())
			->method('authorizeFollowRequest')->with($this->identicalTo($target));
		$this->followService->expects($this->never())->method('rejectFollowRequest');

		$relationship = new Relationship(42);
		$this->followService->expects($this->once())
			->method('getRelationshipWith')->with($this->identicalTo($target))->willReturn($relationship);

		$response = $this->controller()->followRequestAuthorize('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($relationship, $response->getData());
	}

	public function testFollowRequestRejectRejectsAndReturnsTheRelationship(): void {
		$this->loggedInAs();
		$target = $this->knownTarget();
		$this->followService->expects($this->once())
			->method('rejectFollowRequest')->with($this->identicalTo($target));
		$this->followService->expects($this->never())->method('authorizeFollowRequest');

		$relationship = new Relationship(42);
		$this->followService->method('getRelationshipWith')->willReturn($relationship);

		$response = $this->controller()->followRequestReject('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($relationship, $response->getData());
	}

	public function testFollowRequestAuthorizeWithoutAPendingRequestIsNotFound(): void {
		$this->loggedInAs();
		$this->knownTarget();
		$this->followService->method('authorizeFollowRequest')
			->willThrowException(new FollowNotFoundException());

		$response = $this->controller()->followRequestAuthorize('42');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'no pending follow request'], $response->getData());
	}

	public function testFollowRequestsRequireAViewerForActions(): void {
		$this->followService->expects($this->never())->method('authorizeFollowRequest');
		$this->followService->expects($this->never())->method('rejectFollowRequest');

		$this->assertUnauthorized($this->controller()->followRequestAuthorize('42'));
		$this->assertUnauthorized($this->controller()->followRequestReject('42'));
	}

	public function testAFollowRequestRouteRefusesAReadOnlyToken(): void {
		$this->route = 'social.Api.followRequestAuthorize';
		$this->bearerFor(['read']);
		$this->followService->expects($this->never())->method('authorizeFollowRequest');

		$this->assertUnauthorized(
			$this->controller('Bearer s3cret')->followRequestAuthorize('42'),
			'token scope does not allow this request (needs follow or write)'
		);
	}

	public function testAFollowScopedTokenMayAuthorizeAFollowRequest(): void {
		$this->route = 'social.Api.followRequestAuthorize';
		$this->bearerFor(['follow']);
		$target = $this->knownTarget();
		$this->followService->expects($this->once())
			->method('authorizeFollowRequest')->with($this->identicalTo($target));
		$this->followService->method('getRelationshipWith')->willReturn(new Relationship(42));

		$response = $this->controller('Bearer s3cret')->followRequestAuthorize('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// update_credentials

	public function testUpdateCredentialsLocksTheAccountAndReturnsTheRefreshedViewer(): void {
		$viewer = $this->loggedInAs();
		$this->request->method('getParams')->willReturn(['locked' => 'true']);
		$this->accountService->expects($this->once())->method('setLocked')->with('alice', true);

		$response = $this->controller()->updateCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($viewer, $response->getData());
	}

	public function testUpdateCredentialsCanUnlockTheAccount(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['locked' => 'false']);
		$this->accountService->expects($this->once())->method('setLocked')->with('alice', false);

		$this->assertSame(Http::STATUS_OK, $this->controller()->updateCredentials()->getStatus());
	}

	public function testUpdateCredentialsWithoutTheLockedFieldChangesNothing(): void {
		$viewer = $this->loggedInAs();
		$this->request->method('getParams')->willReturn(['display_name' => 'Alice']);
		$this->accountService->expects($this->never())->method('setLocked');

		$response = $this->controller()->updateCredentials();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($viewer, $response->getData());
	}

	public function testUpdateCredentialsRequiresAViewer(): void {
		$this->accountService->expects($this->never())->method('setLocked');

		$this->assertUnauthorized($this->controller()->updateCredentials());
	}

	public function testUpdateCredentialsRefusesAReadOnlyToken(): void {
		$this->route = 'social.Api.updateCredentials';
		$this->bearerFor(['read']);
		$this->accountService->expects($this->never())->method('setLocked');

		$this->assertUnauthorized(
			$this->controller('Bearer s3cret')->updateCredentials(),
			'token scope does not allow this request (needs write)'
		);
	}

	// reports

	public function testReportNewFilesAReportAgainstTheResolvedAccount(): void {
		$viewer = $this->loggedInAs();
		$target = $this->knownTarget();
		$this->request->method('getParams')->willReturn([
			'account_id' => '42',
			'status_ids' => ['7', 8],
			'comment' => 'spam bot',
			'category' => 'spam',
		]);

		$report = new Report();
		$this->reportService->expects($this->once())
			->method('reportFromLocal')
			->with($this->identicalTo($viewer), $this->identicalTo($target), ['7', '8'], 'spam bot', 'spam')
			->willReturn($report);

		$response = $this->controller()->reportNew();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($report, $response->getData());
	}

	public function testReportNewRequiresAnAccountId(): void {
		$this->loggedInAs();
		$this->request->method('getParams')->willReturn(['comment' => 'no target']);
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$response = $this->controller()->reportNew();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testReportNewRefusesReportingYourself(): void {
		$viewer = $this->loggedInAs();
		$this->request->method('getParams')->willReturn(['account_id' => '42']);
		$self = $this->createMock(Person::class);
		$self->method('getNid')->willReturn(42);
		$self->method('getId')->willReturn($viewer->getId());
		$this->cacheActorService->method('getFromNids')->with([42])->willReturn([$self]);
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$response = $this->controller()->reportNew();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testReportNewRequiresAViewer(): void {
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$this->assertUnauthorized($this->controller()->reportNew());
	}

	public function testReportNewRefusesAReadOnlyToken(): void {
		$this->route = 'social.Api.reportNew';
		$this->bearerFor(['read']);
		$this->reportService->expects($this->never())->method('reportFromLocal');

		$this->assertUnauthorized(
			$this->controller('Bearer s3cret')->reportNew(),
			'token scope does not allow this request (needs write)'
		);
	}

	public function testAccountStatusesSyncsThenProbesTheAccountTimeline(): void {
		$actor = $this->createMock(Person::class);
		$actor->method('getId')->willReturn('https://remote.example/users/bob');
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example')->willReturn($actor);
		$this->streamService->expects($this->once())->method('syncRemoteTimeline')->with($actor);
		$options = $this->captureTimelineOptions(['p']);

		$response = $this->controller()->accountStatuses('bob@remote.example', 5, 40, 10, 20);

		$this->assertSame(['p'], $response->getData());
		$probe = $options();
		$this->assertSame(ProbeOptions::ACCOUNT, $probe->getProbe());
		$this->assertSame('https://remote.example/users/bob', $probe->getAccountId());
		$this->assertSame(5, $probe->getLimit());
		$this->assertSame(40, $probe->getMaxId());
		$this->assertSame(10, $probe->getMinId());
		$this->assertSame(20, $probe->getSince());
	}

	public function testAccountStatusesOfUnknownAccountIsAnError(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException('who?'));

		$this->assertUnauthorized($this->controller()->accountStatuses('nobody'), 'who?');
	}

	private function localHosts(): void {
		$this->configService->method('getCloudHost')->willReturn('cloud.example');
		$this->configService->method('getSocialAddress')->willReturn('social.example');
	}

	public function testAccountFollowersOfLocalAccountAreProbedLocally(): void {
		$this->localHosts();
		$actor = $this->createMock(Person::class);
		$actor->method('getId')->willReturn('https://cloud.example/apps/social/@alice');
		$this->cacheActorService->method('getFromAccount')->with('alice@cloud.example')->willReturn($actor);
		$this->curlService->expects($this->never())->method('retrieveObject');
		$captured = null;
		$this->cacheActorService->method('probeActors')->willReturnCallback(function (ProbeOptions $o) use (&$captured): array {
			$captured = $o;

			return ['f'];
		});

		$response = $this->controller()->accountFollowers('alice@cloud.example', 3, 9, 8, 7);

		$this->assertSame(['f'], $response->getData());
		$this->assertSame(ProbeOptions::FOLLOWERS, $captured->getProbe());
		$this->assertSame('https://cloud.example/apps/social/@alice', $captured->getAccountId());
		$this->assertSame([3, 9, 8, 7], [$captured->getLimit(), $captured->getMaxId(), $captured->getMinId(), $captured->getSince()]);
	}

	public function testAccountFollowingOfBareUsernameIsProbedLocally(): void {
		$this->localHosts();
		$actor = $this->createMock(Person::class);
		$this->cacheActorService->method('getFromAccount')->with('alice')->willReturn($actor);
		$captured = null;
		$this->cacheActorService->method('probeActors')->willReturnCallback(function (ProbeOptions $o) use (&$captured): array {
			$captured = $o;

			return [];
		});

		$this->controller()->accountFollowing('alice');

		$this->assertSame(ProbeOptions::FOLLOWING, $captured->getProbe());
	}

	public function testAccountFollowersOfRemoteAccountAreFetchedFromTheirCollection(): void {
		$this->localHosts();
		$actor = $this->createMock(Person::class);
		$actor->method('getFollowers')->willReturn('https://remote.example/users/bob/followers');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->curlService->method('retrieveObject')->willReturnMap([
			['https://remote.example/users/bob/followers', true, ['first' => 'https://remote.example/users/bob/followers?page=1']],
			['https://remote.example/users/bob/followers?page=1', true, ['orderedItems' => [
				'https://remote.example/users/x',
				'https://remote.example/users/y',
				'https://remote.example/users/z',
			]]],
		]);
		$x = $this->createMock(Person::class);
		$y = $this->createMock(Person::class);
		$this->cacheActorService->method('getFromId')->willReturnMap([
			['https://remote.example/users/x', false, $x],
			['https://remote.example/users/y', false, $y],
		]);
		$x->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);
		$this->cacheActorService->expects($this->never())->method('probeActors');

		$response = $this->controller()->accountFollowers('bob@remote.example', 2);

		$this->assertSame([$x, $y], $response->getData(), 'limit is applied and the third actor is never fetched');
	}

	public function testAccountFollowingOfRemoteAccountSkipsUnresolvableActors(): void {
		$this->localHosts();
		$actor = $this->createMock(Person::class);
		$actor->method('getFollowing')->willReturn('https://remote.example/users/bob/following');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->curlService->method('retrieveObject')->willReturn(['items' => ['https://remote.example/users/x', '']]);
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());

		$response = $this->controller()->accountFollowing('bob@remote.example');

		$this->assertSame([], $response->getData());
	}

	public function testRemoteCollectionFetchFailureYieldsAnEmptyList(): void {
		$this->localHosts();
		$actor = $this->createMock(Person::class);
		$actor->method('getFollowers')->willReturn('https://remote.example/users/bob/followers');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->curlService->method('retrieveObject')->willThrowException(new \RuntimeException('timeout'));

		$response = $this->controller()->accountFollowers('bob@remote.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testRemoteFollowerFanOutIsBoundedByMaxLimit(): void {
		$this->localHosts();
		$actor = $this->createMock(Person::class);
		$actor->method('getFollowers')->willReturn('https://remote.example/users/bob/followers');
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);

		// A remote page far larger than MAX_LIMIT, every id unresolvable: each would be
		// an outbound fetch, so the walk itself must stop at ProbeOptions::MAX_LIMIT
		// regardless of the (already large) limit the caller asked for.
		$ids = [];
		for ($i = 0; $i < 500; $i++) {
			$ids[] = 'https://remote.example/users/u' . $i;
		}
		$this->curlService->method('retrieveObject')->willReturn(['orderedItems' => $ids]);

		$calls = 0;
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(function () use (&$calls): Person {
				$calls++;

				throw new CacheActorDoesNotExistException();
			});

		$response = $this->controller()->accountFollowers('bob@remote.example', 500);

		$this->assertSame([], $response->getData());
		$this->assertLessThanOrEqual(ProbeOptions::MAX_LIMIT, $calls, 'the fan-out walk must be bounded by MAX_LIMIT');
	}

	// favourites / notifications / tag

	public function testFavouritesProbeTheFavouritesTimeline(): void {
		$this->loggedInAs();
		$options = $this->captureTimelineOptions(['fav']);

		$response = $this->controller()->favourites(4, 30, 20, 10);

		$this->assertSame(['fav'], $response->getData());
		$probe = $options();
		$this->assertSame(ProbeOptions::FAVOURITES, $probe->getProbe());
		$this->assertSame([4, 30, 20, 10], [$probe->getLimit(), $probe->getMaxId(), $probe->getMinId(), $probe->getSince()]);
	}

	public function testFavouritesRequireAViewer(): void {
		$this->assertUnauthorized($this->controller()->favourites());
	}

	public function testNotificationsForwardTypeFilters(): void {
		$this->loggedInAs();
		$options = $this->captureTimelineOptions();

		$this->controller()->notifications(10, 0, 0, 0, ['mention', 'follow'], ['reblog'], 'https://x/acct');

		$probe = $options();
		$this->assertSame(ProbeOptions::NOTIFICATIONS, $probe->getProbe());
		$this->assertSame(['mention', 'follow'], $probe->getTypes());
		$this->assertSame(['reblog'], $probe->getExcludeTypes());
		$this->assertSame('https://x/acct', $probe->getAccountId());
		$this->assertSame(10, $probe->getLimit());
	}

	public function testNotificationsRequireAViewer(): void {
		$this->assertUnauthorized($this->controller()->notifications());
	}

	public function testTagProbesTheHashtagTimeline(): void {
		$this->loggedInAs();
		$options = $this->captureTimelineOptions(['t']);

		$response = $this->controller()->tag('nextcloud', 6, 0, 0, 0, true, true);

		$this->assertSame(['t'], $response->getData());
		$probe = $options();
		$this->assertSame(ProbeOptions::HASHTAG, $probe->getProbe());
		$this->assertSame('nextcloud', $probe->getArgument());
		$this->assertTrue($probe->isLocal());
		$this->assertTrue($probe->isOnlyMedia());
		$this->assertSame(6, $probe->getLimit());
	}

	// media

	public function testMediaNewWithoutUploadIsABadRequest(): void {
		$this->loggedInAs();

		$response = $this->controller()->mediaNew();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'no media found'], $response->getData());
	}

	public function testMediaNewRequiresAViewer(): void {
		$_FILES['file'] = ['tmp_name' => '/tmp/x', 'size' => 1, 'type' => 'image/png', 'error' => UPLOAD_ERR_OK];
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$response = $this->controller()->mediaNew();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => self::REVOKED], $response->getData());
	}

	public function testMediaNewReportsFailedUploads(): void {
		$this->loggedInAs();
		$_FILES['file'] = ['tmp_name' => '', 'size' => 0, 'type' => '', 'error' => UPLOAD_ERR_PARTIAL];

		$this->assertSame(['error' => 'error during upload'], $this->controller()->mediaNew()->getData());
	}

	public function testMediaNewRejectsUploadsWithoutAType(): void {
		$this->loggedInAs();
		$_FILES['file'] = ['tmp_name' => '/tmp/upload', 'size' => 10, 'type' => '', 'error' => UPLOAD_ERR_OK];
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertSame(['error' => 'missing details'], $this->controller()->mediaNew()->getData());
	}

	public function testMediaNewStoresTheUploadAsAPublicLocalDocument(): void {
		$this->loggedInAs();
		$_FILES['file'] = ['tmp_name' => '/tmp/php-upload', 'size' => 10, 'type' => 'image/png', 'error' => UPLOAD_ERR_OK];
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');

		$saved = null;
		$this->cacheDocumentService->expects($this->once())->method('saveFromTempToCache')
			->willReturnCallback(function (Document $document, string $tmpPath) use (&$saved): void {
				$this->assertSame('/tmp/php-upload', $tmpPath);
				$saved = $document;
			});

		$interface = $this->createMock(IActivityPubInterface::class);
		$interface->expects($this->once())->method('save')->with($this->isInstanceOf(Document::class));
		AP::$activityPub = $this->createMock(AP::class);
		AP::$activityPub->method('getInterfaceForItem')->willReturn($interface);

		$response = $this->controller()->mediaNew();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(MediaAttachment::class, $response->getData());
		$this->assertTrue($saved->isLocal());
		$this->assertTrue($saved->isPublic());
		$this->assertSame('alice', $saved->getAccount());
		$this->assertStringStartsWith('https://cloud.example/documents/local/', $saved->getId());
	}

	public function testBookmarksIsTheBookmarksTimeline(): void {
		$this->loggedInAs();
		$posts = [$this->createMock(Stream::class)];
		$grab = $this->captureTimelineOptions($posts);

		$response = $this->controller()->bookmarks(30, 9, 4, 2);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($posts, $response->getData());
		$options = $grab();
		$this->assertSame('bookmarks', $options->getProbe());
		$this->assertSame(30, $options->getLimit());
		$this->assertSame(9, $options->getMaxId());
		$this->assertSame(4, $options->getMinId());
	}

	public function testMediaNewStoresTheAltText(): void {
		$this->loggedInAs();
		$_FILES['file'] = ['tmp_name' => '/tmp/php-upload', 'size' => 10, 'type' => 'image/png', 'error' => UPLOAD_ERR_OK];
		$this->configService->method('getCloudUrl')->willReturn('https://cloud.example');

		$saved = null;
		$this->cacheDocumentService->method('saveFromTempToCache')
			->willReturnCallback(function (Document $document) use (&$saved): void {
				$saved = $document;
			});
		$interface = $this->createMock(IActivityPubInterface::class);
		AP::$activityPub = $this->createMock(AP::class);
		AP::$activityPub->method('getInterfaceForItem')->willReturn($interface);

		$this->description = 'a cat sleeping on a laptop';
		$this->controller()->mediaNew();

		$this->assertSame('a cat sleeping on a laptop', $saved->getDescription());
	}

	public function testMediaNewV2IsTheSameUpload(): void {
		// modern clients POST /api/v2/media and only fall back to v1 on a 404
		$this->loggedInAs();

		$response = $this->controller()->mediaNewV2();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'no media found'], $response->getData());
	}

	private function ownDocumentInService(string $nid, string $description = ''): Document {
		$document = new Document();
		$document->setNid((int)$nid);
		$document->setId('https://cloud.example/documents/local/doc-' . $nid);
		$document->setDescription($description);
		$this->documentService->method('getMediaFromArray')
			->with([$nid], 'alice')
			->willReturn([$document]);

		return $document;
	}

	public function testMediaGetReturnsTheViewersOwnAttachment(): void {
		$this->loggedInAs();
		$this->ownDocumentInService('7', 'alt text');

		$response = $this->controller()->mediaGet('7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$attachment = $response->getData();
		$this->assertInstanceOf(MediaAttachment::class, $attachment);
		$this->assertSame('7', $attachment->getId());
		$this->assertSame('alt text', $attachment->getDescription());
	}

	public function testMediaGetOfSomeoneElsesAttachmentIsA404(): void {
		$this->loggedInAs();
		$this->documentService->method('getMediaFromArray')->willReturn([]);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->mediaGet('7')->getStatus());
	}

	public function testMediaUpdateChangesTheAltText(): void {
		$this->loggedInAs();
		$document = $this->ownDocumentInService('7', 'old');
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'Content-Type' ? 'application/x-www-form-urlencoded' : ''
		);
		$this->request->method('getParams')->willReturn(['description' => 'new alt text']);
		$this->documentService->expects($this->once())
			->method('updateDescription')
			->with($this->identicalTo($document));

		$response = $this->controller()->mediaUpdate('7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('new alt text', $document->getDescription());
		$this->assertSame('new alt text', $response->getData()->getDescription());
	}

	public function testMediaUpdateOfSomeoneElsesAttachmentIsA404(): void {
		$this->loggedInAs();
		$this->documentService->method('getMediaFromArray')->willReturn([]);
		$this->documentService->expects($this->never())->method('updateDescription');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->mediaUpdate('7')->getStatus());
	}

	public function testMediaOpenServesTheStoredMediaType(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('abc');
		$file->method('getETag')->willReturn('etag');
		$file->method('getMTime')->willReturn(1700000000);
		$document = $this->createMock(\OCA\Social\Model\ActivityPub\Object\Document::class);
		$document->method('getMediaType')->willReturn('image/png');
		$this->documentService->expects($this->once())->method('getFromUuid')->with('abc', true)->willReturn([$file, $document]);

		$response = $this->controller()->mediaOpen('abc.png');

		$this->assertInstanceOf(FileDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('image/png', $response->getHeaders()['Content-Type']);
	}

	public function testMediaOpenIgnoresTheRequestersExtension(): void {
		// the media type was sniffed at ingest; the URL suffix is attacker-chosen
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('abc');
		$document = $this->createMock(\OCA\Social\Model\ActivityPub\Object\Document::class);
		$document->method('getMediaType')->willReturn('image/png');
		$this->documentService->method('getFromUuid')->with('abc', true)->willReturn([$file, $document]);

		$this->assertSame('image/png', $this->controller()->mediaOpen('abc.svg')->getHeaders()['Content-Type']);
	}

	public function testMediaOpenOfUnknownFileIs404(): void {
		$this->documentService->method('getFromUuid')->willThrowException(new NotFoundException('no file'));

		$response = $this->controller()->mediaOpen('abc.png');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'no file'], $response->getData());
	}

	public function testMediaOpenOtherFailuresAreBadRequests(): void {
		$this->documentService->method('getFromUuid')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller()->mediaOpen('abc.png');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'boom'], $response->getData());
	}
}
