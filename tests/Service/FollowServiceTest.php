<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

class FollowServiceTest extends TestCase {
	private const CLOUD_URL = 'https://cloud.example';
	private const ALICE_ID = 'https://social.example/@alice';
	private const BOB_ID = 'https://remote.example/users/bob';
	private const CAROL_ID = 'https://other.example/users/carol';

	private IURLGenerator|MockObject $urlGenerator;
	private FollowsRequest|MockObject $followsRequest;
	private ActivityService|MockObject $activityService;
	private CacheActorService|MockObject $cacheActorService;
	private FollowService $service;

	protected function setUp(): void {
		$this->bootActivityPub();

		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);

		$this->service = new FollowService(
			$this->urlGenerator,
			$this->followsRequest,
			$this->activityService,
			$this->cacheActorService,
			$this->createMock(ConfigService::class),
			new NullLogger()
		);
	}

	protected function tearDown(): void {
		AP::$activityPub = null;
	}

	/**
	 * A real AP with every ActivityPub interface mocked, so getItemFromType()
	 * behaves as in production (including the cloud url needed for ids).
	 */
	private function bootActivityPub(): void {
		$args = [];
		foreach ((new ReflectionClass(AP::class))->getConstructor()->getParameters() as $parameter) {
			$class = $parameter->getType()->getName();
			$mock = $this->createMock($class);
			if ($class === ConfigService::class) {
				$mock->method('getCloudUrl')->willReturn(self::CLOUD_URL);
			}
			$args[] = $mock;
		}
		AP::$activityPub = new AP(...$args);
	}

	private function person(string $id, string $username, int $nid = 0): Person {
		$person = new Person();
		$person->setId($id);
		$person->setNid($nid);
		$person->setPreferredUsername($username);
		$person->setInbox($id . '/inbox');
		$person->setFollowers($id . '/followers');
		$person->setFollowing($id . '/following');

		return $person;
	}

	private function alice(): Person {
		$alice = $this->person(self::ALICE_ID, 'alice', 1);
		$alice->setLocal(true);

		return $alice;
	}

	private function follow(string $actorId, string $objectId, bool $accepted): Follow {
		$follow = new Follow();
		$follow->setId(self::CLOUD_URL . '/follow/' . md5($actorId . $objectId));
		$follow->setActorId($actorId);
		$follow->setObjectId($objectId);
		$follow->setAccepted($accepted);

		return $follow;
	}


	// followAccount()

	public function testFollowAccountSavesFollowAndSendsItToTargetInbox(): void {
		$bob = $this->person(self::BOB_ID, 'bob');
		$this->cacheActorService->expects($this->once())
			->method('getFromAccount')
			->with('bob@remote.example')
			->willReturn($bob);
		$this->followsRequest->expects($this->once())
			->method('getByPersons')
			->with(self::ALICE_ID, self::BOB_ID)
			->willThrowException(new FollowNotFoundException());

		$saved = null;
		$this->followsRequest->expects($this->once())
			->method('save')
			->with($this->callback(function (Follow $follow) use (&$saved): bool {
				$saved = $follow;

				return true;
			}));
		$sent = null;
		$this->activityService->expects($this->once())
			->method('request')
			->with($this->callback(function (ACore $item) use (&$sent): bool {
				$sent = $item;

				return true;
			}))
			->willReturn('token');

		$this->service->followAccount($this->alice(), 'bob@remote.example');

		$this->assertInstanceOf(Follow::class, $saved);
		$this->assertSame($saved, $sent);
		$this->assertSame(Follow::TYPE, $saved->getType());
		$this->assertStringStartsWith(self::CLOUD_URL . '/', $saved->getId());
		$this->assertSame(self::ALICE_ID, $saved->getActorId());
		$this->assertSame(self::BOB_ID, $saved->getObjectId());
		$this->assertSame(self::BOB_ID . '/followers', $saved->getFollowId());
		$this->assertFalse($saved->isAccepted());

		$paths = $sent->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::BOB_ID . '/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_TOP, $paths[0]->getPriority());
	}

	public function testFollowAccountRefusesFollowingYourself(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->alice());
		$this->followsRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(FollowSameAccountException::class);
		$this->service->followAccount($this->alice(), 'alice@social.example');
	}

	public function testFollowAccountIgnoresAnExistingFollow(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::BOB_ID, 'bob'));
		$this->followsRequest->method('getByPersons')->willReturn($this->follow(self::ALICE_ID, self::BOB_ID, true));
		$this->followsRequest->expects($this->never())->method('save');
		$this->activityService->expects($this->never())->method('request');

		$this->service->followAccount($this->alice(), 'bob@remote.example');
	}

	public function testFollowAccountKeepsTheFollowWhenFederationFails(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::BOB_ID, 'bob'));
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());
		$this->followsRequest->expects($this->once())->method('save');
		$this->activityService->method('request')->willThrowException(new \RuntimeException('queue down'));

		$this->service->followAccount($this->alice(), 'bob@remote.example');
	}

	public function testFollowAccountFailsForUnknownAccount(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->followsRequest->expects($this->never())->method('save');

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->service->followAccount($this->alice(), 'nobody@remote.example');
	}


	// unfollowAccount()

	public function testUnfollowAccountDeletesFollowAndSendsUndo(): void {
		$bob = $this->person(self::BOB_ID, 'bob');
		$follow = $this->follow(self::ALICE_ID, self::BOB_ID, true);
		$this->cacheActorService->method('getFromAccount')->with('bob@remote.example')->willReturn($bob);
		$this->followsRequest->method('getByPersons')->with(self::ALICE_ID, self::BOB_ID)->willReturn($follow);
		$this->followsRequest->expects($this->once())->method('delete')->with($this->identicalTo($follow));

		$sent = null;
		$this->activityService->expects($this->once())
			->method('request')
			->with($this->callback(function (ACore $item) use (&$sent): bool {
				$sent = $item;

				return true;
			}))
			->willReturn('token');

		$this->service->unfollowAccount($this->alice(), 'bob@remote.example');

		$this->assertInstanceOf(Undo::class, $sent);
		$this->assertStringContainsString('#undo/follows/', $sent->getId());
		$this->assertSame(self::ALICE_ID, $sent->getActorId());
		$this->assertSame($follow, $sent->getObject());
		$this->assertSame($sent, $follow->getParent());
		$this->assertSame($follow->getId(), $sent->getObjectId());

		$paths = $sent->getInstancePaths();
		$this->assertCount(1, $paths);
		$this->assertSame(self::BOB_ID . '/inbox', $paths[0]->getUri());
		$this->assertSame(InstancePath::TYPE_INBOX, $paths[0]->getType());
		$this->assertSame(InstancePath::PRIORITY_TOP, $paths[0]->getPriority());
	}

	public function testUnfollowAccountIsANoopWhenNotFollowing(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::BOB_ID, 'bob'));
		$this->followsRequest->method('getByPersons')->willThrowException(new FollowNotFoundException());
		$this->followsRequest->expects($this->never())->method('delete');
		$this->activityService->expects($this->never())->method('request');

		$this->service->unfollowAccount($this->alice(), 'bob@remote.example');
	}


	// getLinksBetweenPersons()

	/**
	 * @return array<string, array{bool, bool}>
	 */
	public function linksProvider(): array {
		return [
			'no relation' => [false, false],
			'local follows actor' => [true, false],
			'actor follows local' => [false, true],
			'mutual' => [true, true],
		];
	}

	/**
	 * @dataProvider linksProvider
	 */
	public function testGetLinksBetweenPersonsReportsBothDirections(bool $following, bool $follower): void {
		$this->followsRequest->method('getByPersons')
			->willReturnCallback(function (string $actorId, string $remoteId) use ($following, $follower): Follow {
				if ($actorId === self::ALICE_ID && $remoteId === self::BOB_ID && $following) {
					return $this->follow($actorId, $remoteId, true);
				}
				if ($actorId === self::BOB_ID && $remoteId === self::ALICE_ID && $follower) {
					return $this->follow($actorId, $remoteId, true);
				}
				throw new FollowNotFoundException();
			});

		$links = $this->service->getLinksBetweenPersons($this->alice(), $this->person(self::BOB_ID, 'bob'));

		$this->assertSame(['follower' => $follower, 'following' => $following], $links);
	}


	// followers / following

	public function testGetFollowersAndFollowingDelegateWithActorId(): void {
		$followers = [$this->follow(self::BOB_ID, self::ALICE_ID, true)];
		$following = [$this->follow(self::ALICE_ID, self::CAROL_ID, true)];
		$this->followsRequest->method('getFollowersByActorId')->with(self::ALICE_ID)->willReturn($followers);
		$this->followsRequest->method('getFollowingByActorId')->with(self::ALICE_ID)->willReturn($following);

		$this->assertSame($followers, $this->service->getFollowers($this->alice()));
		$this->assertSame($following, $this->service->getFollowing($this->alice()));
	}

	public function testGetFollowersFromFollowIdDelegates(): void {
		$followers = [$this->follow(self::BOB_ID, self::ALICE_ID, true)];
		$this->followsRequest->method('getFollowersByFollowId')
			->with(self::ALICE_ID . '/followers')
			->willReturn($followers);

		$this->assertSame($followers, $this->service->getFollowersFromFollowId(self::ALICE_ID . '/followers'));
	}

	public function testGetFollowersCollectionDescribesFollowersEndpoint(): void {
		$alice = $this->alice();
		$alice->setDetailArray('count', ['followers' => 12, 'following' => 4]);
		$this->urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('social.ActivityPub.followers', ['username' => 'alice'])
			->willReturn('https://cloud.example/apps/social/@alice/followers');

		$collection = $this->service->getFollowersCollection($alice);

		$this->assertSame('OrderedCollection', $collection->getType());
		$this->assertSame(self::ALICE_ID . '/followers', $collection->getId());
		$this->assertSame(12, $collection->getTotalItems());
		$this->assertSame('https://cloud.example/apps/social/@alice/followers?page=1', $collection->getFirst());
		$this->assertSame('', $collection->getLast());
	}

	public function testGetFollowingCollectionDescribesFollowingEndpoint(): void {
		$alice = $this->alice();
		$alice->setDetailArray('count', ['followers' => 12, 'following' => 4]);
		$this->urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('social.ActivityPub.following', ['username' => 'alice'])
			->willReturn('https://cloud.example/apps/social/@alice/following');

		$collection = $this->service->getFollowingCollection($alice);

		$this->assertSame(self::ALICE_ID . '/following', $collection->getId());
		$this->assertSame(4, $collection->getTotalItems());
		$this->assertSame('https://cloud.example/apps/social/@alice/following?page=1', $collection->getFirst());
	}

	public function testCollectionsWithoutCachedCountsReportZero(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/x');

		$this->assertSame(0, $this->service->getFollowersCollection($this->alice())->getTotalItems());
		$this->assertSame(0, $this->service->getFollowingCollection($this->alice())->getTotalItems());
	}


	// setViewer() / getRelationships()

	public function testSetViewerIsPassedToFollowsRequest(): void {
		$alice = $this->alice();
		$this->followsRequest->expects($this->once())->method('setViewer')->with($this->identicalTo($alice));

		$this->service->setViewer($alice);
	}

	public function testGetRelationshipsResolvesNidsAndUrlsAndSkipsTheViewer(): void {
		$alice = $this->alice();
		$bob = $this->person(self::BOB_ID, 'bob', 2);
		$carol = $this->person(self::CAROL_ID, 'carol', 3);
		$this->service->setViewer($alice);

		$this->cacheActorService->expects($this->once())
			->method('getFromNids')
			->with([2, 1])
			->willReturn([$bob, $alice]);
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with(self::CAROL_ID)
			->willReturn($carol);
		$this->followsRequest->method('getByPersons')
			->willReturnCallback(function (string $actorId, string $remoteId): Follow {
				// alice follows bob (accepted), bob follows alice (accepted),
				// alice asked to follow carol (pending), carol does not follow alice
				return match ([$actorId, $remoteId]) {
					[self::ALICE_ID, self::BOB_ID] => $this->follow($actorId, $remoteId, true),
					[self::BOB_ID, self::ALICE_ID] => $this->follow($actorId, $remoteId, true),
					[self::ALICE_ID, self::CAROL_ID] => $this->follow($actorId, $remoteId, false),
					default => throw new FollowNotFoundException(),
				};
			});

		$relationships = $this->service->getRelationships(['2', self::CAROL_ID, '1']);

		$this->assertCount(2, $relationships);

		$this->assertSame(2, $relationships[0]->getId());
		$this->assertTrue($relationships[0]->isFollowing());
		$this->assertTrue($relationships[0]->isFollowedBy());
		$this->assertFalse($relationships[0]->isRequested());

		$this->assertSame(3, $relationships[1]->getId());
		$this->assertFalse($relationships[1]->isFollowing());
		$this->assertFalse($relationships[1]->isFollowedBy());
		$this->assertTrue($relationships[1]->isRequested());
	}

	public function testGetRelationshipsSkipsUnknownActors(): void {
		$this->service->setViewer($this->alice());
		$this->cacheActorService->method('getFromNids')->with([])->willReturn([]);
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->followsRequest->expects($this->never())->method('getByPersons');

		$this->assertSame([], $this->service->getRelationships(['https://gone.example/users/x', '0', 'abc']));
	}

	public function testGetRelationshipsDoesNotFlagPendingFollowerAsFollowedBy(): void {
		$alice = $this->alice();
		$bob = $this->person(self::BOB_ID, 'bob', 2);
		$this->service->setViewer($alice);
		$this->cacheActorService->method('getFromNids')->willReturn([$bob]);
		$this->followsRequest->method('getByPersons')
			->willReturnCallback(function (string $actorId, string $remoteId): Follow {
				if ($actorId === self::BOB_ID) {
					return $this->follow($actorId, $remoteId, false);
				}
				throw new FollowNotFoundException();
			});

		$relationships = $this->service->getRelationships([2]);

		$this->assertCount(1, $relationships);
		$this->assertFalse($relationships[0]->isFollowedBy());
		$this->assertFalse($relationships[0]->isFollowing());
		$this->assertFalse($relationships[0]->isRequested());
	}
}
