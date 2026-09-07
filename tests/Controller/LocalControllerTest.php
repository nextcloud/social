<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\LocalController;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LocalControllerTest extends TestCase {
	/** @var IRequest&MockObject */
	private $request;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var HashtagService&MockObject */
	private $hashtagService;
	/** @var FollowService&MockObject */
	private $followService;
	/** @var PostService&MockObject */
	private $postService;
	/** @var StreamService&MockObject */
	private $streamService;
	/** @var SearchService&MockObject */
	private $searchService;
	/** @var BoostService&MockObject */
	private $boostService;
	/** @var LikeService&MockObject */
	private $likeService;
	/** @var DocumentService&MockObject */
	private $documentService;
	/** @var ConfigService&MockObject */
	private $configService;
	/** @var ActorService&MockObject */
	private $actorService;
	/** @var ActivityService&MockObject */
	private $activityService;
	/** @var CacheDocumentService&MockObject */
	private $cacheDocumentService;

	private array $filesBackup;

	protected function setUp(): void {
		$this->filesBackup = $_FILES;
		$_FILES = [];

		$this->request = $this->createMock(IRequest::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->hashtagService = $this->createMock(HashtagService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->postService = $this->createMock(PostService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->searchService = $this->createMock(SearchService::class);
		$this->boostService = $this->createMock(BoostService::class);
		$this->likeService = $this->createMock(LikeService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->actorService = $this->createMock(ActorService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);

		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		$_FILES = $this->filesBackup;
		\OC::$server->reset();
	}

	private function controller(?string $userId = 'alice'): LocalController {
		return new LocalController(
			$this->request,
			$userId,
			$this->accountService,
			$this->cacheActorService,
			$this->hashtagService,
			$this->followService,
			$this->postService,
			$this->streamService,
			$this->searchService,
			$this->boostService,
			$this->likeService,
			$this->documentService,
			$this->createMock(MiscService::class),
			$this->configService,
			new NullLogger(),
			$this->actorService,
			$this->activityService,
			$this->cacheDocumentService
		);
	}

	/** @return Person&MockObject */
	private function actorForUser(string $userId = 'alice'): Person {
		$actor = $this->createMock(Person::class);
		$actor->method('getId')->willReturn('https://cloud.example/apps/social/@' . $userId);
		$actor->method('getPreferredUsername')->willReturn($userId);
		$this->accountService->method('getActorFromUserId')
			->with($userId, $this->anything())
			->willReturn($actor);

		return $actor;
	}

	private function assertSuccess(DataResponse $response, $result): void {
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['result' => $result, 'status' => 1], $response->getData());
	}

	private function assertFailure(DataResponse $response, string $exceptionClass, ?string $message = null, int $status = Http::STATUS_INTERNAL_SERVER_ERROR): void {
		$this->assertSame($status, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(-1, $data['status']);
		// internals ($exceptionClass, $message) must never reach the response
		$this->assertSame('request failed', $data['error']);
		$this->assertArrayNotHasKey('exception', $data);
		if ($message !== null) {
			$this->assertArrayNotHasKey('message', $data);
		}
	}

	private function assertNotLoggedIn(DataResponse $response): void {
		$this->assertFailure($response, AccountDoesNotExistException::class, 'User not logged in');
	}


	// postCreate()

	public function testPostCreateBuildsThePostFromTheRequest(): void {
		$actor = $this->actorForUser();
		$object = $this->createMock(Note::class);
		$activity = $this->createMock(ACore::class);
		$activity->method('getObject')->willReturn($object);

		$created = null;
		$this->postService->expects($this->once())->method('createPost')
			->willReturnCallback(function (Post $post, string &$token) use (&$created, $activity): ACore {
				$created = $post;
				$token = 'tok-123';

				return $activity;
			});

		$response = $this->controller()->postCreate(
			'Hello <b>world</b>', ['bob@remote.example'], 'followers', 'https://remote.example/n/1',
			['https://cloud.example/documents/1'], ['nextcloud']
		);

		$this->assertSuccess($response, ['post' => $object, 'token' => 'tok-123']);
		$this->assertSame($actor, $created->getActor());
		$this->assertSame('Hello <b>world</b>', $created->getContent());
		$this->assertSame(['bob@remote.example'], $created->getTo());
		$this->assertSame('followers', $created->getType());
		$this->assertSame('https://remote.example/n/1', $created->getReplyTo());
		$this->assertSame(['https://cloud.example/documents/1'], $created->getAttachments());
		$this->assertSame(['nextcloud'], $created->getHashtags());
	}

	public function testPostCreateDefaultsToAPublicTopLevelPost(): void {
		$this->actorForUser();
		$created = null;
		$this->postService->method('createPost')->willReturnCallback(function (Post $post) use (&$created): ACore {
			$created = $post;

			return $this->createMock(ACore::class);
		});

		$this->controller()->postCreate('just text');

		$this->assertSame(Stream::TYPE_PUBLIC, $created->getType());
		$this->assertSame('', $created->getReplyTo());
		$this->assertSame([], $created->getTo());
		$this->assertSame([], $created->getAttachments());
		$this->assertSame([], $created->getHashtags());
	}

	public function testPostCreateRequiresALoggedInUser(): void {
		$this->postService->expects($this->never())->method('createPost');

		$this->assertNotLoggedIn($this->controller(null)->postCreate('x'));
	}

	public function testPostCreateReportsServiceFailures(): void {
		$this->actorForUser();
		$this->postService->method('createPost')->willThrowException(new \RuntimeException('cannot post'));

		$this->assertFailure($this->controller()->postCreate('x'), \RuntimeException::class, 'cannot post');
	}


	// postGet() / postReplies() / postDelete()

	public function testPostGetReturnsTheStreamDirectly(): void {
		$this->actorForUser();
		$stream = $this->createMock(Stream::class);
		$this->streamService->method('getStreamById')->with('https://x/n/1', true)->willReturn($stream);

		$response = $this->controller()->postGet('https://x/n/1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($stream, $response->getData());
	}

	public function testPostGetWorksAnonymously(): void {
		$stream = $this->createMock(Stream::class);
		$this->streamService->method('getStreamById')->willReturn($stream);
		$this->accountService->expects($this->never())->method('getActorFromUserId');

		$this->assertSame($stream, $this->controller(null)->postGet('https://x/n/1')->getData());
	}

	public function testPostGetOfUnknownStreamFails(): void {
		$this->streamService->method('getStreamById')->willThrowException(new StreamNotFoundException());

		$this->assertFailure($this->controller(null)->postGet('https://x/n/404'), StreamNotFoundException::class);
	}

	public function testPostRepliesPassPagination(): void {
		$this->actorForUser();
		$this->streamService->expects($this->once())->method('getRepliesByParentId')
			->with('https://x/n/1', 100, 20, true)->willReturn(['r']);

		$this->assertSuccess($this->controller()->postReplies('https://x/n/1', 100, 20), ['r']);
	}

	public function testPostDeleteRemovesTheUsersOwnNote(): void {
		$this->actorForUser();
		$note = $this->createMock(Stream::class);
		$note->method('getAttributedTo')->willReturn('https://cloud.example/apps/social/@alice');
		$this->streamService->method('getStreamById')->with('https://x/n/1')->willReturn($note);
		$this->streamService->expects($this->once())->method('deleteLocalItem')->with($note, Note::TYPE);

		$this->assertSuccess($this->controller()->postDelete('https://x/n/1'), []);
	}

	public function testPostDeleteRefusesSomeoneElsesNote(): void {
		$this->actorForUser();
		$note = $this->createMock(Stream::class);
		$note->method('getAttributedTo')->willReturn('https://remote.example/users/bob');
		$this->streamService->method('getStreamById')->willReturn($note);
		$this->streamService->expects($this->never())->method('deleteLocalItem');

		$this->assertFailure($this->controller()->postDelete('https://x/n/1'), InvalidResourceException::class, 'user have no rights');
	}

	public function testPostDeleteRequiresALoggedInUser(): void {
		$this->streamService->expects($this->never())->method('getStreamById');

		$this->assertNotLoggedIn($this->controller(null)->postDelete('https://x/n/1'));
	}


	// like / boost

	/** @return iterable<string, array{string, string, string, string}> */
	public function reactions(): iterable {
		yield 'like' => ['postLike', 'likeService', 'create', 'like'];
		yield 'unlike' => ['postUnlike', 'likeService', 'delete', 'like'];
		yield 'boost' => ['postBoost', 'boostService', 'create', 'boost'];
		yield 'unboost' => ['postUnboost', 'boostService', 'delete', 'boost'];
	}

	/** @dataProvider reactions */
	public function testReactionsAreCreatedForTheViewer(string $action, string $service, string $method, string $key): void {
		$viewer = $this->actorForUser();
		$activity = $this->createMock(ACore::class);
		$this->$service->expects($this->once())->method($method)
			->willReturnCallback(function (Person $actor, string $postId, string &$token) use ($viewer, $activity): ACore {
				$this->assertSame($viewer, $actor);
				$this->assertSame('https://x/n/1', $postId);
				$token = 'tok';

				return $activity;
			});

		$this->assertSuccess($this->controller()->$action('https://x/n/1'), [$key => $activity, 'token' => 'tok']);
	}

	/** @dataProvider reactions */
	public function testReactionsRequireALoggedInUser(string $action, string $service, string $method): void {
		$this->$service->expects($this->never())->method($method);

		$this->assertFailure($this->controller(null)->$action('https://x/n/1'), AccountDoesNotExistException::class, 'userId not defined');
	}

	public function testReactionFailuresAreReported(): void {
		$this->actorForUser();
		$this->likeService->method('create')->willThrowException(new StreamNotFoundException('no such post'));

		$this->assertFailure($this->controller()->postLike('https://x/n/404'), StreamNotFoundException::class, 'no such post');
	}


	// follow / unfollow

	public function testActionFollowFollowsAndRefreshesCounters(): void {
		$actor = $this->actorForUser();
		$this->followService->expects($this->once())->method('followAccount')->with($actor, 'bob@remote.example');
		$this->accountService->expects($this->once())->method('cacheLocalActorDetailCount')->with($actor);

		$this->assertSuccess($this->controller()->actionFollow('bob@remote.example'), []);
	}

	public function testActionFollowMapsServiceExceptionsToFailures(): void {
		$this->actorForUser();
		$this->followService->method('followAccount')->willThrowException(new FollowSameAccountException("Don't follow yourself, be your own lead"));
		$this->accountService->expects($this->never())->method('cacheLocalActorDetailCount');

		$this->assertFailure($this->controller()->actionFollow('alice'), FollowSameAccountException::class, "Don't follow yourself, be your own lead");
	}

	public function testActionFollowRequiresALoggedInUser(): void {
		$this->followService->expects($this->never())->method('followAccount');

		$this->assertNotLoggedIn($this->controller(null)->actionFollow('bob'));
	}

	public function testActionUnfollowUnfollowsAndRefreshesCounters(): void {
		$actor = $this->actorForUser();
		$this->followService->expects($this->once())->method('unfollowAccount')->with($actor, 'bob@remote.example');
		$this->accountService->expects($this->once())->method('cacheLocalActorDetailCount')->with($actor);

		$this->assertSuccess($this->controller()->actionUnfollow('bob@remote.example'), []);
	}

	public function testActionUnfollowMapsServiceExceptionsToFailures(): void {
		$this->actorForUser();
		$this->followService->method('unfollowAccount')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertFailure($this->controller()->actionUnfollow('ghost'), CacheActorDoesNotExistException::class);
	}


	// stream*()

	/** @return iterable<string, array{string, array, string, array}> */
	public function streams(): iterable {
		yield 'home' => ['streamHome', [10, 25], 'getStreamHome', [10, 25]];
		yield 'notifications' => ['streamNotifications', [3, 7], 'getStreamNotifications', [3, 7]];
		yield 'direct' => ['streamDirect', [1, 2], 'getStreamDirect', [1, 2]];
		yield 'local timeline' => ['streamTimeline', [5, 15], 'getStreamLocalTimeline', [5, 15]];
		yield 'tag' => ['streamTag', ['nextcloud', 4, 8], 'getStreamLocalTag', ['nextcloud', 4, 8]];
		yield 'federated' => ['streamFederated', [9, 9], 'getStreamGlobalTimeline', [9, 9]];
		yield 'liked' => ['streamLiked', [0, 5], 'getStreamLiked', [0, 5]];
	}

	/** @dataProvider streams */
	public function testStreamEndpointsPassSinceAndLimitToTheService(string $action, array $args, string $method, array $expected): void {
		$viewer = $this->actorForUser();
		$this->streamService->expects($this->once())->method('setViewer')->with($viewer);
		$posts = [$this->createMock(Stream::class)];
		$this->streamService->expects($this->once())->method($method)->with(...$expected)->willReturn($posts);

		$this->assertSuccess($this->controller()->$action(...$args), $posts);
	}

	/** @dataProvider streams */
	public function testStreamEndpointsRequireALoggedInUser(string $action, array $args, string $method): void {
		$this->streamService->expects($this->never())->method($method);

		$this->assertFailure($this->controller(null)->$action(...$args), AccountDoesNotExistException::class, 'userId not defined');
	}

	public function testStreamEndpointsFailWhenTheViewerCannotBeResolved(): void {
		$this->accountService->method('getActorFromUserId')->willThrowException(new AccountDoesNotExistException('no actor'));

		$response = $this->controller()->streamHome();

		$this->assertFailure($response, AccountDoesNotExistException::class);
	}

	public function testStreamAccountSyncsTheRemoteTimelineFirst(): void {
		$account = $this->createMock(Person::class);
		$account->method('getId')->willReturn('https://remote.example/users/bob');
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example')->willReturn($account);
		$this->streamService->expects($this->once())->method('syncRemoteTimeline')->with($account);
		$this->streamService->expects($this->once())->method('getStreamAccount')
			->with('https://remote.example/users/bob', 2, 6)->willReturn(['p']);

		$this->assertSuccess($this->controller(null)->streamAccount('bob@remote.example', 2, 6), ['p']);
	}


	// current* / account* / global*

	public function testCurrentInfoRefreshesAndReturnsTheCachedActor(): void {
		$this->actorForUser();
		$cached = $this->createMock(Person::class);
		$this->accountService->expects($this->once())->method('cacheLocalActorByUsername')->with('alice');
		$this->cacheActorService->method('getFromLocalAccount')->with('alice')->willReturn($cached);

		$this->assertSuccess($this->controller()->currentInfo(), ['account' => $cached]);
	}

	public function testCurrentInfoRequiresALoggedInUser(): void {
		$this->assertNotLoggedIn($this->controller(null)->currentInfo());
	}

	public function testCurrentFollowersAndFollowingListTheUsersRelations(): void {
		$actor = $this->actorForUser();
		$this->followService->method('getFollowers')->with($actor)->willReturn(['f1']);
		$this->followService->method('getFollowing')->with($actor)->willReturn(['f2']);

		$this->assertSuccess($this->controller()->currentFollowers(), ['f1']);
		$this->assertSuccess($this->controller()->currentFollowing(), ['f2']);
	}

	public function testCurrentFollowersRequireALoggedInUser(): void {
		$this->assertNotLoggedIn($this->controller(null)->currentFollowers());
		$this->assertNotLoggedIn($this->controller(null)->currentFollowing());
	}

	public function testAccountInfoReturnsTheCompleteLocalActor(): void {
		$actor = $this->createMock(Person::class);
		$this->cacheActorService->method('getFromLocalAccount')->with('bob')->willReturn($actor);
		$actor->expects($this->once())->method('setCompleteDetails')->with(true);
		$actor->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);

		$response = $this->controller(null)->accountInfo('bob');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($actor, $response->getData());
	}

	public function testAccountInfoRebuildsTheCacheOnAMiss(): void {
		$actor = $this->createMock(Person::class);
		$this->cacheActorService->method('getFromLocalAccount')->with('bob')
			->will($this->onConsecutiveCalls($this->throwException(new CacheActorDoesNotExistException()), $actor));
		$this->accountService->expects($this->once())->method('cacheLocalActorByUsername')->with('bob');

		$this->assertSame($actor, $this->controller(null)->accountInfo('bob')->getData());
	}

	public function testAccountInfoOfUnknownUserFails(): void {
		$this->cacheActorService->method('getFromLocalAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->accountService->method('cacheLocalActorByUsername')->willThrowException(new AccountDoesNotExistException());

		$this->assertFailure($this->controller(null)->accountInfo('ghost'), CacheActorDoesNotExistException::class);
	}

	public function testAccountFollowersAndFollowingOfALocalUser(): void {
		$actor = $this->createMock(Person::class);
		$this->cacheActorService->method('getFromLocalAccount')->with('bob')->willReturn($actor);
		$this->followService->method('getFollowers')->with($actor)->willReturn(['f1']);
		$this->followService->method('getFollowing')->with($actor)->willReturn(['f2']);

		$this->assertSuccess($this->controller(null)->accountFollowers('bob'), ['f1']);
		$this->assertSuccess($this->controller(null)->accountFollowing('bob'), ['f2']);
	}

	public function testGlobalAccountInfoEnsuresTheViewersOwnActorExists(): void {
		$actor = $this->createMock(Person::class);
		$actor->method('isLocal')->willReturn(true);
		// once from initViewer(), once — with create=true — from the ensure path
		$created = false;
		$this->accountService->method('getActorFromUserId')
			->willReturnCallback(function (string $userId, bool $create = false) use (&$created): Person {
				$this->assertSame('bob', $userId);
				$created = $created || $create;

				return $this->createMock(Person::class);
			});
		$this->accountService->expects($this->once())->method('cacheLocalActorByUsername')->with('bob');
		$this->cacheActorService->method('getFromLocalAccount')->with('bob')->willReturn($actor);
		$this->cacheActorService->expects($this->never())->method('getFromAccount');
		$this->cacheActorService->expects($this->never())->method('addRemoteActorDetailCount');
		$actor->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);

		$response = $this->controller('bob')->globalAccountInfo('@bob');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($actor, $response->getData());
		$this->assertTrue($created, 'the viewer asking about their own account creates the actor');
	}

	public function testGlobalAccountInfoNeverCreatesAnActorForOtherVisitors(): void {
		// the route is public: creating here would let anonymous visitors force a
		// Fediverse identity onto any Nextcloud user, and probe which users exist
		$actor = $this->createMock(Person::class);
		$actor->method('isLocal')->willReturn(true);
		$this->accountService->expects($this->never())->method('getActorFromUserId');
		$this->cacheActorService->method('getFromLocalAccount')->with('bob')->willReturn($actor);

		$response = $this->controller(null)->globalAccountInfo('@bob');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($actor, $response->getData());
	}

	public function testGlobalAccountInfoTreatsOwnDomainAsLocal(): void {
		$this->configService->method('getCloudHost')->willReturn('cloud.example');
		$this->configService->method('getSocialAddress')->willReturn('social.example');
		$actor = $this->createMock(Person::class);
		$actor->method('isLocal')->willReturn(true);
		$this->cacheActorService->method('getFromLocalAccount')->with('bob')->willReturn($actor);
		$this->cacheActorService->expects($this->never())->method('getFromAccount');

		$this->assertSame($actor, $this->controller(null)->globalAccountInfo('bob@social.example')->getData());
	}

	public function testGlobalAccountInfoFetchesRemoteActorsWithTheirCounters(): void {
		$this->configService->method('getCloudHost')->willReturn('cloud.example');
		$this->configService->method('getSocialAddress')->willReturn('cloud.example');
		$actor = $this->createMock(Person::class);
		$actor->method('isLocal')->willReturn(false);
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example')->willReturn($actor);
		$this->cacheActorService->expects($this->once())->method('addRemoteActorDetailCount')->with($actor);
		$this->accountService->expects($this->never())->method('getActorFromUserId');

		$this->assertSame($actor, $this->controller(null)->globalAccountInfo('bob@remote.example')->getData());
	}

	public function testGlobalAccountInfoToleratesCounterFailures(): void {
		$this->configService->method('getCloudHost')->willReturn('cloud.example');
		$actor = $this->createMock(Person::class);
		$actor->method('isLocal')->willReturn(false);
		$this->cacheActorService->method('getFromAccount')->willReturn($actor);
		$this->cacheActorService->method('addRemoteActorDetailCount')->willThrowException(new \RuntimeException('offline'));

		$this->assertSame(Http::STATUS_OK, $this->controller(null)->globalAccountInfo('bob@remote.example')->getStatus());
	}

	public function testGlobalAccountInfoOfUnknownRemoteAccountFails(): void {
		$this->configService->method('getCloudHost')->willReturn('cloud.example');
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertFailure($this->controller(null)->globalAccountInfo('ghost@remote.example'), CacheActorDoesNotExistException::class);
	}

	public function testGlobalActorInfoLooksUpById(): void {
		$actor = $this->createMock(Person::class);
		$this->cacheActorService->method('getFromId')->with('https://remote.example/users/bob')->willReturn($actor);

		$this->assertSuccess($this->controller(null)->globalActorInfo('https://remote.example/users/bob'), ['actor' => $actor]);
	}

	public function testGlobalActorInfoOfUnknownIdFails(): void {
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertFailure($this->controller(null)->globalActorInfo('https://x'), CacheActorDoesNotExistException::class);
	}


	// avatar / header

	public function testGlobalActorAvatarServesTheCachedIcon(): void {
		\OC::$server->register(ITimeFactory::class, $this->createMock(ITimeFactory::class));
		$icon = $this->createMock(Document::class);
		$icon->method('getId')->willReturn('https://remote.example/avatar.png');
		$actor = $this->createMock(Person::class);
		$actor->method('hasIcon')->willReturn(true);
		$actor->method('getIcon')->willReturn($icon);
		$this->cacheActorService->method('getFromId')->willReturn($actor);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('avatar');
		$this->documentService->method('getFromCache')
			->willReturnCallback(function (string $id, string &$mime) use ($file): ISimpleFile {
				$this->assertSame('https://remote.example/avatar.png', $id);
				$mime = 'image/png';

				return $file;
			});

		$response = $this->controller(null)->globalActorAvatar('https://remote.example/users/bob');

		$this->assertInstanceOf(FileDisplayResponse::class, $response);
		$headers = $response->getHeaders();
		$this->assertSame('image/png', $headers['Content-Type']);
		$this->assertStringContainsString('max-age=86400', $headers['Cache-Control']);
	}

	public function testGlobalActorAvatarIs404WithoutIcon(): void {
		$actor = $this->createMock(Person::class);
		$actor->method('hasIcon')->willReturn(false);
		$this->cacheActorService->method('getFromId')->willReturn($actor);

		$this->assertFailure(
			$this->controller(null)->globalActorAvatar('https://x'),
			InvalidResourceException::class, 'no avatar for this Actor', Http::STATUS_NOT_FOUND
		);
	}

	public function testGlobalActorHeaderRedirectsToTheHeaderImage(): void {
		\OC::$server->register(ITimeFactory::class, $this->createMock(ITimeFactory::class));
		$actor = $this->createMock(Person::class);
		$actor->method('getHeader')->willReturn('https://remote.example/header.jpg');
		$this->cacheActorService->method('getFromId')->willReturn($actor);

		$response = $this->controller(null)->globalActorHeader('https://x');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('https://remote.example/header.jpg', $response->getRedirectURL());
		$this->assertStringContainsString('max-age=86400', $response->getHeaders()['Cache-Control']);
	}

	public function testGlobalActorHeaderIs404WithoutHeader(): void {
		$actor = $this->createMock(Person::class);
		$actor->method('getHeader')->willReturn('');
		$this->cacheActorService->method('getFromId')->willReturn($actor);

		$this->assertFailure(
			$this->controller(null)->globalActorHeader('https://x'),
			InvalidResourceException::class, 'no header for this Actor', Http::STATUS_NOT_FOUND
		);
	}


	// search

	public function testGlobalAccountsSearchWithEmptyTermIsEmpty(): void {
		$this->cacheActorService->expects($this->never())->method('searchCachedAccounts');

		$this->assertSuccess($this->controller()->globalAccountsSearch('@'), ['accounts' => [], 'exact' => []]);
	}

	public function testGlobalAccountsSearchStripsTheAtAndReturnsExactMatch(): void {
		$this->actorForUser();
		$exact = $this->createMock(Person::class);
		$exact->expects($this->once())->method('setCompleteDetails')->with(true);
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example', false)->willReturn($exact);
		$this->cacheActorService->method('searchCachedAccounts')->with('bob@remote.example')->willReturn(['a1', 'a2']);

		$this->assertSuccess(
			$this->controller()->globalAccountsSearch('@bob@remote.example'),
			['accounts' => ['a1', 'a2'], 'exact' => $exact]
		);
	}

	public function testGlobalAccountsSearchWithoutExactMatch(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->cacheActorService->method('searchCachedAccounts')->willReturn([]);

		$this->assertSuccess($this->controller(null)->globalAccountsSearch('nobody'), ['accounts' => [], 'exact' => null]);
	}

	public function testGlobalTagsSearchWithEmptyTermIsEmpty(): void {
		$this->hashtagService->expects($this->never())->method('searchHashtags');

		$this->assertSuccess($this->controller(null)->globalTagsSearch('#'), ['tags' => [], 'exact' => []]);
	}

	public function testGlobalTagsSearchStripsTheHashAndReturnsExactMatch(): void {
		$this->hashtagService->method('getHashtag')->with('nextcloud')->willReturn(['hashtag' => 'nextcloud']);
		$this->hashtagService->method('searchHashtags')->with('nextcloud', false)->willReturn(['t1']);

		$this->assertSuccess(
			$this->controller(null)->globalTagsSearch('#nextcloud'),
			['tags' => ['t1'], 'exact' => ['hashtag' => 'nextcloud']]
		);
	}

	public function testSearchTrimsTheTermAndQueriesAllKinds(): void {
		$this->searchService->method('searchAccounts')->with('term')->willReturn(['a']);
		$this->searchService->method('searchHashtags')->with('term')->willReturn(['h']);
		$this->searchService->method('searchStreamContent')->with('term')->willReturn(['c']);

		$this->assertSuccess(
			$this->controller(null)->search('  term '),
			['accounts' => ['a'], 'hashtags' => ['h'], 'content' => ['c']]
		);
	}


	// documents / uploads

	public function testDocumentsCacheSkipsDocumentsThatCannotBeCached(): void {
		$doc = $this->createMock(Document::class);
		$this->documentService->method('cacheRemoteDocument')->willReturnCallback(function (string $id) use ($doc): Document {
			if ($id === 'bad') {
				throw new \RuntimeException('unreachable');
			}

			return $doc;
		});

		$this->assertSuccess($this->controller()->documentsCache(['good', 'bad']), [$doc]);
	}

	public function testUploadAttachementIsNotImplemented(): void {
		$this->assertFailure($this->controller()->uploadAttachement(), \BadMethodCallException::class);
	}

	public function testUploadBannerRequiresALoggedInUser(): void {
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertNotLoggedIn($this->controller(null)->uploadBanner());
	}

	public function testUploadBannerRequiresAFile(): void {
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertFailure($this->controller()->uploadBanner(), \Exception::class, 'no banner file provided');
	}

	public function testUploadBannerRejectsFailedUploads(): void {
		$_FILES['file'] = ['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE];
		$this->accountService->expects($this->never())->method('getActorFromUserId');

		$this->assertFailure($this->controller()->uploadBanner(), \Exception::class, 'no banner file provided');
	}

	public function testUploadBannerByUrlRequiresALoggedInUser(): void {
		$this->assertNotLoggedIn($this->controller(null)->uploadBannerByUrl('https://example.org/banner.png'));
	}

	public function testUploadBannerByUrlRequiresAUrl(): void {
		$this->accountService->expects($this->never())->method('getActorFromUserId');

		$this->assertFailure($this->controller()->uploadBannerByUrl(''), \Exception::class, 'No URL provided');
	}
}
