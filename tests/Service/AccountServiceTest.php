<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\SignatureService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AccountServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example.com/apps/social/@alice';

	private IUserManager|MockObject $userManager;
	private IUserSession|MockObject $userSession;
	private IAccountManager|MockObject $accountManager;
	private ActorsRequest|MockObject $actorsRequest;
	private FollowsRequest|MockObject $followsRequest;
	private StreamRequest|MockObject $streamRequest;
	private ActorService|MockObject $actorService;
	private ActivityService|MockObject $activityService;
	private DocumentService|MockObject $documentService;
	private SignatureService|MockObject $signatureService;
	private AccountService $service;
	private int $errorReporting;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->accountManager = $this->createMock(IAccountManager::class);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->actorService = $this->createMock(ActorService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->signatureService = $this->createMock(SignatureService::class);

		// AccountService assigns its collaborators to undeclared properties; PHP reports
		// "Creation of dynamic property" for each of them, which PHPUnit would treat as
		// unexpected output. Silence that single known deprecation around construction.
		$this->errorReporting = error_reporting(E_ALL & ~E_DEPRECATED);
		$this->service = new AccountService(
			$this->userManager,
			$this->userSession,
			$this->accountManager,
			$this->actorsRequest,
			$this->followsRequest,
			$this->streamRequest,
			$this->actorService,
			$this->activityService,
			$this->documentService,
			$this->signatureService,
			$this->createMock(ConfigService::class),
			new NullLogger(),
		);
		error_reporting($this->errorReporting);
	}

	protected function tearDown(): void {
		AP::$activityPub = null;
		error_reporting($this->errorReporting);
	}

	private function user(string $uid): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE);
		$alice->setUserId('alice');
		$alice->setPreferredUsername('alice');
		$alice->setInbox(self::ALICE . '/inbox');
		$alice->setLocal(true);

		return $alice;
	}

	/** Point the display-name account property at a value with the given scope. */
	private function withDisplayName(string $name, string $scope): void {
		$property = $this->createMock(IAccountProperty::class);
		$property->method('getScope')->willReturn($scope);
		$property->method('getValue')->willReturn($name);
		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->with(IAccountManager::PROPERTY_DISPLAYNAME)->willReturn($property);
		$this->accountManager->method('getAccount')->willReturn($account);
	}

	public function testGetActorAndGetFromIdDelegate(): void {
		$alice = $this->alice();
		$this->actorsRequest->expects($this->once())->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->once())->method('getFromId')->with(self::ALICE)->willReturn($alice);

		$this->assertSame($alice, $this->service->getActor('alice'));
		$this->assertSame($alice, $this->service->getFromId(self::ALICE));
	}

	public function testConfirmUserIdNormalisesToTheRealUid(): void {
		$this->userManager->method('get')->with('Alice')->willReturn($this->user('alice'));

		$userId = 'Alice';
		$user = $this->service->confirmUserId($userId);

		$this->assertSame('alice', $userId);
		$this->assertSame('alice', $user->getUID());
	}

	public function testGetCurrentViewerRequiresALoggedInUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(AccountDoesNotExistException::class);
		$this->expectExceptionMessage('No user is currently logged in');
		$this->service->getCurrentViewer();
	}

	public function testGetCurrentViewerReturnsTheActorOfTheSessionUser(): void {
		$this->userSession->method('getUser')->willReturn($this->user('alice'));
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($alice);

		$this->assertSame($alice, $this->service->getCurrentViewer());
	}

	public function testGetCurrentViewerWrapsAMissingActor(): void {
		$this->userSession->method('getUser')->willReturn($this->user('alice'));
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUserId')->willThrowException(new ActorDoesNotExistException());

		$this->expectException(AccountDoesNotExistException::class);
		$this->expectExceptionMessage('Account not found for current user');
		$this->service->getCurrentViewer();
	}

	public function testGetActorFromUserIdWithoutCreateThrowsForAMissingActor(): void {
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUserId')->willThrowException(new ActorDoesNotExistException());
		$this->actorsRequest->expects($this->never())->method('create');

		$this->expectException(ActorDoesNotExistException::class);
		$this->expectExceptionMessage('Actor not found for user: alice');
		$this->service->getActorFromUserId('alice');
	}

	public function testGetActorFromUserIdCreatesTheActorOnDemand(): void {
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());
		$created = null;
		$this->actorsRequest->expects($this->once())
			->method('create')
			->willReturnCallback(function (Person $actor) use (&$created) {
				$created = $actor;
			});
		// missing before creation (looked up twice), found afterwards
		$this->actorsRequest->expects($this->exactly(3))
			->method('getFromUserId')
			->with('alice')
			->willReturnCallback(function () use (&$created) {
				if ($created === null) {
					throw new ActorDoesNotExistException();
				}

				return $created;
			});

		$actor = $this->service->getActorFromUserId('alice', true);

		$this->assertSame($created, $actor);
		$this->assertSame('alice', $actor->getUserId());
	}

	public function testCreateActorGeneratesKeysStoresTheActorAndItsLoopbackFollow(): void {
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());
		$this->actorsRequest->method('getFromUserId')->willThrowException(new ActorDoesNotExistException());
		$this->signatureService->expects($this->once())
			->method('generateKeys')
			->willReturnCallback(function (Person $actor) {
				$actor->setPublicKey('PUB');
				$actor->setPrivateKey('PRIV');
			});
		$created = null;
		$this->actorsRequest->expects($this->once())
			->method('create')
			->willReturnCallback(function (Person $actor) use (&$created) {
				$created = $actor;
			});
		$this->followsRequest->expects($this->once())
			->method('generateLoopbackAccount')
			->with($this->callback(function (Person $actor) use (&$created) {
				return $actor === $created;
			}));

		$this->service->createActor('alice', 'alice');

		$this->assertInstanceOf(Person::class, $created);
		$this->assertSame('alice', $created->getUserId());
		$this->assertSame('alice', $created->getPreferredUsername());
		$this->assertSame('PUB', $created->getPublicKey());
		$this->assertSame('PRIV', $created->getPrivateKey());
	}

	public function testCreateActorRefusesATakenUsername(): void {
		$this->userManager->method('get')->willReturn($this->user('bob'));
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($this->alice());
		$this->actorsRequest->expects($this->never())->method('create');

		$this->expectException(AccountAlreadyExistsException::class);
		$this->expectExceptionMessage('already exist');
		$this->service->createActor('bob', 'alice');
	}

	public function testCreateActorRefusesAUsernameStillInDeletionRetention(): void {
		$this->userManager->method('get')->willReturn($this->user('bob'));
		$deleted = $this->alice();
		$deleted->setDeleted(time() - 10);
		$this->actorsRequest->method('getFromUsername')->willReturn($deleted);

		$this->expectException(AccountAlreadyExistsException::class);
		$this->expectExceptionMessage('retention');
		$this->service->createActor('bob', 'alice');
	}

	public function testCreateActorRefusesASecondAccountForTheSameUser(): void {
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($this->alice());
		$this->actorsRequest->expects($this->never())->method('create');

		$this->expectException(AccountAlreadyExistsException::class);
		$this->expectExceptionMessage('account for this user already exist');
		$this->service->createActor('alice', 'alice2');
	}

	public function testDeleteActorMarksDeletesAndBroadcasts(): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->once())->method('setAsDeleted')->with('alice');
		$personInterface = $this->createMock(PersonInterface::class);
		$personInterface->expects($this->once())->method('delete')->with($this->identicalTo($alice));
		$ap = $this->createMock(AP::class);
		$ap->method('getInterfaceFromType')->with(Person::TYPE)->willReturn($personInterface);
		AP::$activityPub = $ap;
		$this->signatureService->expects($this->once())
			->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Delete::class));
		$this->activityService->expects($this->once())
			->method('request')
			->with($this->callback(function (Delete $delete) {
				$this->assertSame(self::ALICE . '#delete', $delete->getId());
				$this->assertSame(self::ALICE, $delete->getActorId());
				$this->assertSame(self::ALICE, $delete->getObjectId());
				$this->assertSame([ACore::CONTEXT_PUBLIC], $delete->getToArray());
				$paths = $delete->getInstancePaths();
				$this->assertCount(1, $paths);
				$this->assertSame(self::ALICE . '/inbox', $paths[0]->getUri());
				$this->assertSame(InstancePath::TYPE_ALL, $paths[0]->getType());
				$this->assertSame(InstancePath::PRIORITY_LOW, $paths[0]->getPriority());

				return true;
			}))
			->willReturn('token');

		$this->service->deleteActor('alice');
	}

	public function testDeleteActorIgnoresUnknownHandles(): void {
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());
		$this->actorsRequest->expects($this->never())->method('setAsDeleted');
		$this->activityService->expects($this->never())->method('request');

		$this->service->deleteActor('nobody');
	}

	public function testCacheLocalActorByUsernameEnrichesAndCachesTheActor(): void {
		$alice = $this->alice();
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->withDisplayName('Alice Wonder', IAccountManager::SCOPE_PUBLISHED);
		$this->documentService->expects($this->once())->method('cacheLocalAvatarByUsername')->with($this->identicalTo($alice))->willReturn('icon-42');
		$this->actorService->method('getCachedHeader')->with($this->identicalTo($alice))->willReturn('https://cloud.example.com/header.jpg');
		$this->followsRequest->method('countFollowers')->with(self::ALICE)->willReturn(3);
		$this->followsRequest->method('countFollowing')->with(self::ALICE)->willReturn(2);
		$this->streamRequest->method('countNotesFromActorId')->with(self::ALICE)->willReturn(7);
		$lastNote = new Note();
		$lastNote->setPublishedTime(mktime(12, 0, 0, 9, 1, 2026));
		$this->streamRequest->method('lastNoteFromActorId')->with(self::ALICE)->willReturn($lastNote);
		$this->actorService->expects($this->once())->method('cacheLocalActor')->with($this->identicalTo($alice));

		$this->service->cacheLocalActorByUsername('alice');

		$this->assertSame('Alice Wonder', $alice->getName());
		$this->assertSame('icon-42', $alice->getIconId());
		$this->assertSame('https://cloud.example.com/header.jpg', $alice->getHeader());
		$this->assertSame(
			['followers' => 3, 'following' => 2, 'follow_requests' => 0, 'post' => 7],
			$alice->getDetails('count')
		);
		$this->assertSame('2026-09-01', $alice->getDetailsAll()['last_post_creation']);
	}

	public function testCacheLocalActorByUsernameKeepsAPrivateDisplayNameOut(): void {
		$alice = $this->alice();
		$alice->setName('alice');
		$this->actorsRequest->method('getFromUsername')->willReturn($alice);
		$this->userManager->method('get')->willReturn($this->user('alice'));
		$this->withDisplayName('Alice Wonder', IAccountManager::SCOPE_PRIVATE);
		$this->documentService->method('cacheLocalAvatarByUsername')->willThrowException(new ItemUnknownException());
		$this->streamRequest->method('lastNoteFromActorId')->willThrowException(new StreamNotFoundException());
		$this->actorService->expects($this->once())->method('cacheLocalActor');

		$this->service->cacheLocalActorByUsername('alice');

		$this->assertSame('alice', $alice->getName());
		$this->assertSame('', $alice->getIconId());
		$this->assertSame('', $alice->getDetailsAll()['last_post_creation']);
	}

	public function testCacheLocalActorByUsernameIgnoresUnknownActors(): void {
		$this->actorsRequest->method('getFromUsername')->willThrowException(new ActorDoesNotExistException());
		$this->actorService->expects($this->never())->method('cacheLocalActor');

		$this->service->cacheLocalActorByUsername('nobody');
	}

	public function testCacheLocalActorDetailCountOnlyAppliesToLocalActors(): void {
		$remote = new Person();
		$remote->setId('https://remote.example/users/bob');
		$this->actorService->expects($this->never())->method('cacheLocalActorDetails');

		$this->service->cacheLocalActorDetailCount($remote);
	}

	public function testCacheLocalActorDetailCountStoresTheCounters(): void {
		$alice = $this->alice();
		$this->followsRequest->method('countFollowers')->willReturn(1);
		$this->followsRequest->method('countFollowing')->willReturn(4);
		$this->followsRequest->method('countPendingRequests')->willReturn(2);
		$this->streamRequest->method('countNotesFromActorId')->willReturn(9);
		$this->streamRequest->method('lastNoteFromActorId')->willThrowException(new StreamNotFoundException());
		$this->actorService->expects($this->once())->method('cacheLocalActorDetails')->with($this->identicalTo($alice));

		$this->service->cacheLocalActorDetailCount($alice);

		$this->assertSame(
			['followers' => 1, 'following' => 4, 'follow_requests' => 2, 'post' => 9],
			$alice->getDetails('count')
		);
	}

	public function testSetLockedStoresTheFlagAndRefreshesTheCache(): void {
		$alice = $this->alice();
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($alice);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->once())
			->method('updateLocked')
			->willReturnCallback(function (Person $actor): void {
				$this->assertTrue($actor->isLocked());
			});
		// the refreshed cache document is what federates manuallyApprovesFollowers
		$this->actorService->expects($this->once())->method('cacheLocalActor')
			->with($this->identicalTo($alice));

		$this->service->setLocked('alice', true);

		$this->assertTrue($alice->isLocked());
	}

	public function testSetLockedCanUnlock(): void {
		$alice = $this->alice();
		$alice->setLocked(true);
		$this->userManager->method('get')->with('alice')->willReturn($this->user('alice'));
		$this->actorsRequest->method('getFromUserId')->with('alice')->willReturn($alice);
		$this->actorsRequest->method('getFromUsername')->with('alice')->willReturn($alice);
		$this->actorsRequest->expects($this->once())->method('updateLocked');

		$this->service->setLocked('alice', false);

		$this->assertFalse($alice->isLocked());
	}

	public function testManageCacheLocalActorsRefreshesEveryLocalActor(): void {
		$alice = $this->alice();
		$bob = $this->alice();
		$bob->setPreferredUsername('bob');
		$this->actorsRequest->method('getAll')->willReturn([$alice, $bob]);
		$this->actorsRequest->expects($this->exactly(2))
			->method('getFromUsername')
			->withConsecutive(['alice'], ['bob'])
			->willThrowException(new ActorDoesNotExistException());

		$this->assertSame(2, $this->service->manageCacheLocalActors());
	}

	public function testManageDeletedActorsSkipsLiveActors(): void {
		$this->actorsRequest->method('getAll')->willReturn([$this->alice()]);
		$this->actorsRequest->expects($this->never())->method('delete');

		$this->assertSame(0, $this->service->manageDeletedActors());
	}

	public function testBlindKeyRotationWithoutActorsDoesNothing(): void {
		$this->actorsRequest->method('getAll')->willReturn([]);
		$this->signatureService->expects($this->never())->method('generateKeys');

		$this->assertSame(0, $this->service->blindKeyRotation());
	}
}
