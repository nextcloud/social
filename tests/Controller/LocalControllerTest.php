<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\LocalController;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\BoostService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LocalControllerTest extends TestCase {
	/** @var IRequest&MockObject */
	private $request;
	/** @var BannerService&MockObject */
	private $bannerService;
	/** @var AccountService&MockObject */
	private $accountService;
	/** @var CacheActorService&MockObject */
	private $cacheActorService;
	/** @var CacheActorsRequest&MockObject */
	private $cacheActorsRequest;
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

	/** @var array<string, Person> actors this instance already has cached locally */
	private array $localActors = [];
	/** @var array<string, ISimpleFile> remote urls whose bytes are already cached */
	private array $cachedRemoteFiles = [];

	protected function setUp(): void {
		$this->bannerService = $this->createMock(BannerService::class);
		$this->filesBackup = $_FILES;
		$_FILES = [];

		$this->request = $this->createMock(IRequest::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		// the public routes read the local actor cache first and only resolve an
		// unknown id for a caller with a session
		$this->localActors = [];
		$this->cacheActorsRequest->method('getFromId')->willReturnCallback(
			function (string $id): Person {
				if (!array_key_exists($id, $this->localActors)) {
					throw new CacheActorDoesNotExistException('not cached');
				}

				return $this->localActors[$id];
			}
		);
		$this->hashtagService = $this->createMock(HashtagService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->postService = $this->createMock(PostService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->searchService = $this->createMock(SearchService::class);
		$this->boostService = $this->createMock(BoostService::class);
		$this->likeService = $this->createMock(LikeService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->cachedRemoteFiles = [];
		$this->documentService->method('getCachedFromUrl')->willReturnCallback(
			function (string $url, string &$mime) {
				if (!array_key_exists($url, $this->cachedRemoteFiles)) {
					throw new CacheDocumentDoesNotExistException('not cached');
				}
				$mime = 'image/jpeg';

				return $this->cachedRemoteFiles[$url];
			}
		);
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
			$this->cacheActorsRequest,
			$this->hashtagService,
			$this->followService,
			$this->postService,
			$this->streamService,
			$this->documentService,
			$this->configService,
			new NullLogger(),
			$this->cacheDocumentService,
			$this->bannerService
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

	/**
	 * A 400 that says why.
	 *
	 * `assertFailure()` asserts that nothing reaches the caller but "request
	 * failed", which is right for a route that failed on the server. These
	 * four are raised about the file the caller just sent, so the caller is
	 * the one person entitled to read them -- and without the reason, "too
	 * big" and "not an image" arrive as the same blank failure.
	 */
	private function assertRefused(DataResponse $response, ?string $message = null): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(-1, $data['status']);
		$this->assertSame('request failed', $data['error']);
		$this->assertArrayNotHasKey('exception', $data);
		$this->assertArrayHasKey('message', $data);
		if ($message !== null) {
			$this->assertSame($message, $data['message']);
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

	public function testAPostTheServiceRefusesIsAnswered422WithTheReason(): void {
		// 500 and 'request failed' reads as "the server broke"; the composer
		// has to be able to say why the post was not made
		$this->actorForUser();
		$this->postService->method('createPost')
			->willThrowException(new InvalidActionException('a post may not be longer than 5000 characters'));

		$response = $this->controller()->postCreate('far too much');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			['status' => -1, 'error' => 'a post may not be longer than 5000 characters'],
			$response->getData()
		);
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
		$this->assertSame('', $created->getSpoilerText());
	}

	public function testPostCreateCarriesTheContentWarning(): void {
		$this->actorForUser();
		$created = null;
		$this->postService->method('createPost')->willReturnCallback(function (Post $post) use (&$created): ACore {
			$created = $post;

			return $this->createMock(ACore::class);
		});

		$this->controller()->postCreate('who shot him', [], null, null, null, [], null, 'season finale');

		$this->assertSame('season finale', $created->getSpoilerText());
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

	// postDelete()

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

	// current* / account* / global*

	public function testAccountInfoReturnsTheCompleteLocalActor(): void {
		$actor = $this->createMock(Person::class);
		$this->accountService->method('getCachedLocalActor')->with('bob')->willReturn($actor);
		$actor->expects($this->once())->method('setCompleteDetails')->with(true);
		$actor->expects($this->once())->method('setExportFormat')->with(ACore::FORMAT_LOCAL);

		$response = $this->controller(null)->accountInfo('bob');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($actor, $response->getData());
	}

	public function testAccountInfoOfUnknownUserFails(): void {
		$this->accountService->method('getCachedLocalActor')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertFailure($this->controller(null)->accountInfo('ghost'), CacheActorDoesNotExistException::class);
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
		$this->accountService->method('getCachedLocalActor')->with('bob')->willReturn($actor);
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
		$this->accountService->method('getCachedLocalActor')->with('bob')->willReturn($actor);

		$response = $this->controller(null)->globalAccountInfo('@bob');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($actor, $response->getData());
	}

	public function testGlobalAccountInfoTreatsOwnDomainAsLocal(): void {
		$this->configService->method('getCloudHost')->willReturn('cloud.example');
		$this->configService->method('getSocialAddress')->willReturn('social.example');
		$actor = $this->createMock(Person::class);
		$actor->method('isLocal')->willReturn(true);
		$this->accountService->method('getCachedLocalActor')->with('bob')->willReturn($actor);
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

	// knownActor(), through the one public route left on it: the avatar

	public function testAnAnonymousCallerCannotMakeTheInstanceResolveAnUnknownActor(): void {
		// resolving an id fetches whatever url it names and downloads the
		// actor's icon: not something anybody may trigger without a session
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$response = $this->controller(null)->globalActorAvatar('https://evil.test/a/1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testALoggedInCallerStillResolvesAnUnknownActor(): void {
		$actor = $this->createMock(Person::class);
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->with('https://remote.example/users/new')->willReturn($actor);

		// resolved -- and then a 404 for the avatar it does not have, which is
		// the route's answer and not a refusal to look
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice')->globalActorAvatar('https://remote.example/users/new')->getStatus());
	}

	public function testTheKeyFragmentIsNotPartOfTheActorId(): void {
		// a keyId names the actor plus `#main-key`; the actor is found by the
		// id alone, from the cache, without asking the network for the fragment
		$this->localActors['https://remote.example/users/bob'] = $this->createMock(Person::class);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->controller('alice')->globalActorAvatar('https://remote.example/users/bob#main-key');
		$this->addToAssertionCount(1);
	}

	// avatar / header

	public function testGlobalActorAvatarServesTheCachedIcon(): void {
		\OC::$server->register(ITimeFactory::class, $this->createMock(ITimeFactory::class));
		$icon = $this->createMock(Document::class);
		$icon->method('getId')->willReturn('https://remote.example/avatar.png');
		$actor = $this->createMock(Person::class);
		$actor->method('hasIcon')->willReturn(true);
		$actor->method('getIcon')->willReturn($icon);
		$this->localActors['https://remote.example/users/bob'] = $actor;
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
		$this->localActors['https://x'] = $actor;

		$this->assertFailure(
			$this->controller(null)->globalActorAvatar('https://x'),
			InvalidResourceException::class, 'no avatar for this Actor', Http::STATUS_NOT_FOUND
		);
	}

	public function testGlobalActorAvatarOfAnUnknownActorIs404ForAnonymousCallers(): void {
		// the download this would start is the point: it writes attacker-chosen
		// bytes into appdata, so an anonymous caller never reaches it
		$this->cacheActorService->expects($this->never())->method('getFromId');
		$this->documentService->expects($this->never())->method('getFromCache');

		$this->assertFailure(
			$this->controller(null)->globalActorAvatar('https://evil.test/a/1'),
			CacheActorDoesNotExistException::class, 'unknown actor', Http::STATUS_NOT_FOUND
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

	// documents / uploads

	public function testUploadBannerRequiresALoggedInUser(): void {
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertNotLoggedIn($this->controller(null)->uploadBanner());
	}

	public function testUploadBannerRequiresAFile(): void {
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertRefused($this->controller()->uploadBanner(), 'No file was sent.');
	}

	public function testUploadBannerRejectsFailedUploads(): void {
		$_FILES['file'] = ['tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE];
		$this->accountService->expects($this->never())->method('getActorFromUserId');

		$this->assertRefused($this->controller()->uploadBanner(), 'No file was sent.');
	}

	/**
	 * A photo off a phone is bigger than `upload_max_filesize` on a stock PHP,
	 * and this used to be a 500 reading "request failed": the reader was told
	 * the server had broken, about something only they could fix.
	 */
	#[DataProvider('provideOversizedUploads')]
	public function testUploadBannerSaysWhenThePictureIsTooBig(int $error): void {
		$_FILES['file'] = ['tmp_name' => '/tmp/whatever', 'error' => $error];
		$this->accountService->expects($this->never())->method('getActorFromUserId');

		$response = $this->controller()->uploadBanner();

		$this->assertRefused($response);
		$this->assertStringContainsString(
			'larger than this server accepts', $response->getData()['message']
		);
	}

	/** @return array<string, array{int}> */
	public static function provideOversizedUploads(): array {
		return [
			'over upload_max_filesize' => [UPLOAD_ERR_INI_SIZE],
			'over the form\'s own limit' => [UPLOAD_ERR_FORM_SIZE],
		];
	}

	/**
	 * PHP discards the whole body when it is over `post_max_size` -- `$_FILES`
	 * and `$_POST` both arrive empty -- so a file too big by a factor of five
	 * is indistinguishable from no file at all except by what was sent.
	 */
	public function testUploadBannerSaysWhenThePostWasDiscardedWhole(): void {
		$this->request->method('getHeader')->willReturnCallback(
			static fn (string $header): string
				=> ($header === 'Content-Length') ? (string)(PHP_INT_MAX - 1) : ''
		);
		$this->accountService->expects($this->never())->method('getActorFromUserId');

		$response = $this->controller()->uploadBanner();

		$this->assertRefused($response);
		$this->assertStringContainsString(
			'larger than this server accepts', $response->getData()['message']
		);
	}

	/**
	 * No temporary directory is this side's problem and the reader can do
	 * nothing about it, so it stays a 500 and says nothing.
	 */
	public function testUploadBannerKeepsTheServersOwnFailuresToItself(): void {
		$_FILES['file'] = ['tmp_name' => '', 'error' => UPLOAD_ERR_NO_TMP_DIR];
		$this->accountService->expects($this->never())->method('getActorFromUserId');

		$response = $this->controller()->uploadBanner();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayNotHasKey('message', $response->getData());
	}

	public function testUploadBannerHandsTheTempFileToTheService(): void {
		$_FILES['file'] = ['tmp_name' => '/tmp/php-upload-1', 'error' => UPLOAD_ERR_OK];
		$image = new Image();
		$image->setId('https://cloud.example/documents/header/1');
		$image->setUrl('https://cloud.example/media/1.png');
		$this->bannerService->expects($this->once())
			->method('setFromTempFile')
			->with('alice', '/tmp/php-upload-1')
			->willReturn($image);

		$response = $this->controller()->uploadBanner();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('https://cloud.example/media/1.png', $response->getData()['result']['url']);
	}

	public function testUploadBannerByUrlRequiresALoggedInUser(): void {
		$this->assertNotLoggedIn($this->controller(null)->uploadBannerByUrl('https://example.org/banner.png'));
	}

	public function testUploadBannerByUrlRequiresAUrl(): void {
		$this->accountService->expects($this->never())->method('getActorFromUserId');

		$this->assertFailure($this->controller()->uploadBannerByUrl(''), \Exception::class, 'No URL provided');
	}

	public function testUploadBannerByUrlDownloadsThroughTheAppsHttpClient(): void {
		// the hand-rolled curl this replaces followed redirects itself and
		// checked only the first host, so a 302 to a local address was fetched
		// unchecked; the app's client re-checks every hop
		// the host check itself needs no network here: the admin has opted in,
		// which is exactly the case where the per-redirect check has to hold
		$this->configService->method('isLocalNetworkAllowed')->willReturn(true);
		$this->cacheDocumentService->expects($this->once())->method('retrieveContent')
			->with('https://cdn.example/banner.png')
			->willThrowException(new \RuntimeException('refused by the client'));

		$this->assertFailure(
			$this->controller()->uploadBannerByUrl('https://cdn.example/banner.png'),
			\RuntimeException::class
		);
	}

	#[DataProvider('provideRefusedBannerUrls')]
	public function testUploadBannerByUrlRefusesWhatIsNotAPublicWebAddress(string $url): void {
		$this->configService->method('isLocalNetworkAllowed')->willReturn(false);
		$this->cacheDocumentService->expects($this->never())->method('retrieveContent');

		$this->assertFailure(
			$this->controller()->uploadBannerByUrl($url), \Exception::class, 'Unsupported banner URL'
		);
	}

	public static function provideRefusedBannerUrls(): iterable {
		yield 'loopback' => ['http://127.0.0.1/x.png'];
		yield 'localhost' => ['http://localhost/x.png'];
		yield 'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'];
		yield 'file' => ['file:///etc/passwd'];
		yield 'gopher' => ['gopher://evil.test/1'];
		yield 'no host' => ['https:///x.png'];
	}

	public function testUploadBannerByUrlRefusesAnOversizedDownload(): void {
		$this->configService->method('isLocalNetworkAllowed')->willReturn(true);
		$this->cacheDocumentService->method('retrieveContent')
			->willReturn(str_repeat('A', 10 * 1024 * 1024 + 1));
		$this->cacheDocumentService->expects($this->never())->method('saveFromTempToCache');

		$this->assertFailure(
			$this->controller()->uploadBannerByUrl('https://cdn.example/banner.png'),
			\Exception::class, 'Banner image is too large'
		);
	}

	public function testPublicRoutesThatReachOutToRemoteServersAreRateLimited(): void {
		// #[PublicPage], and it fetches from whatever host the handle names:
		// globalAccountInfo signs half a dozen outbound requests per call. An
		// anonymous throttle is all that stands between one HTTP request and
		// that work being repeated at will, the way OStatusController::getLink
		// is already throttled. (streamAccount, which pulled a remote outbox,
		// was retired with the rest of the superseded Custom Local API.)
		$reflection = new \ReflectionClass(LocalController::class);

		foreach (['globalAccountInfo'] as $route) {
			$attributes = array_map(
				fn (\ReflectionAttribute $attribute): string => $attribute->getName(),
				$reflection->getMethod($route)->getAttributes()
			);

			$this->assertContains(PublicPage::class, $attributes, $route . ' is expected to stay public');
			$this->assertContains(AnonRateLimit::class, $attributes, $route . ' is public but not throttled for anonymous callers');
			$this->assertContains(UserRateLimit::class, $attributes, $route . ' is not throttled for sessions');
		}
	}
}
