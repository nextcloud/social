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
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Instance;
use OCA\Social\Model\Post;
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
	/** @var StreamService&MockObject */
	private $streamService;
	/** @var ActionService&MockObject */
	private $actionService;
	/** @var PostService&MockObject */
	private $postService;
	/** @var ConfigService&MockObject */
	private $configService;
	/** @var CurlService&MockObject */
	private $curlService;

	private array $filesBackup;

	protected function setUp(): void {
		$this->filesBackup = $_FILES;
		$_FILES = [];

		$this->request = $this->createMock(IRequest::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->instanceService = $this->createMock(InstanceService::class);
		$this->clientService = $this->createMock(ClientService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->actionService = $this->createMock(ActionService::class);
		$this->postService = $this->createMock(PostService::class);
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
			$this->streamService,
			$this->actionService,
			$this->postService,
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
		$this->assertSame("hello<br />\nworld", $created->getContent());
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
			->with(5, $this->isInstanceOf(Person::class), "edited<br />\ntext", 'cw', true)
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

	public function testMediaGetIsAStubReturningNothing(): void {
		$response = $this->controller()->mediaGet('1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testMediaOpenServesTheFileWithMimeDerivedFromExtension(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('abc');
		$file->method('getETag')->willReturn('etag');
		$file->method('getMTime')->willReturn(1700000000);
		$this->documentService->expects($this->once())->method('getFromUuid')->with('abc', true)->willReturn([$file, $this->createMock(\OCA\Social\Model\ActivityPub\Object\Document::class)]);

		$response = $this->controller()->mediaOpen('abc.png');

		$this->assertInstanceOf(FileDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('image/png', $response->getHeaders()['Content-Type']);
	}

	public function testMediaOpenWithoutExtensionHasNoContentType(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('abc');
		$this->documentService->method('getFromUuid')->with('abc', true)->willReturn([$file, $this->createMock(\OCA\Social\Model\ActivityPub\Object\Document::class)]);

		$this->assertSame('', $this->controller()->mediaOpen('abc')->getHeaders()['Content-Type']);
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
