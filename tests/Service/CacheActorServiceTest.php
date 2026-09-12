<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CacheActorServiceTest extends TestCase {
	private const BOB = 'https://remote.example/users/bob';
	private const ALICE = 'https://cloud.example.com/apps/social/@alice';

	private ActorsRequest|MockObject $actorsRequest;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private CurlService|MockObject $curlService;
	private ConfigService|MockObject $configService;
	private AP|MockObject $ap;
	private PersonInterface|MockObject $personInterface;
	private CacheActorService $service;

	protected function setUp(): void {
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getCloudHost')->willReturn('cloud.example.com');
		$this->configService->method('getSocialAddress')->willReturn('social.example.com');

		$this->ap = $this->createMock(AP::class);
		$this->personInterface = $this->createMock(PersonInterface::class);
		$this->ap->method('getInterfaceFromType')->with(Person::TYPE)->willReturn($this->personInterface);
		$this->ap->method('isActor')->willReturnCallback(fn ($item) => $item instanceof Person);
		AP::set($this->ap);

		$this->service = new CacheActorService(
			$this->createMock(IURLGenerator::class),
			$this->actorsRequest,
			$this->cacheActorsRequest,
			$this->curlService,
			$this->createMock(FediverseService::class),
			$this->configService,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	private function person(string $id, string $username = 'bob'): Person {
		$person = new Person();
		$person->setId($id);
		$person->setPreferredUsername($username);

		return $person;
	}

	public function testGetFromIdReturnsTheCachedActorAndIgnoresTheKeyFragment(): void {
		$bob = $this->person(self::BOB);
		$this->cacheActorsRequest->expects($this->once())->method('getFromId')->with(self::BOB)->willReturn($bob);
		$this->curlService->expects($this->never())->method('retrieveObject');

		$this->assertSame($bob, $this->service->getFromId(self::BOB . '#main-key'));
	}

	public function testGetFromIdFetchesSavesAndReturnsAnUnknownActor(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$data = ['id' => self::BOB, 'type' => 'Person', 'preferredUsername' => 'bob', '_host' => 'remote.example'];
		$this->curlService->expects($this->once())->method('retrieveObject')->with(self::BOB)->willReturn($data);
		$bob = $this->person(self::BOB);
		$this->ap->expects($this->once())->method('getItemFromData')->with($data)->willReturn($bob);
		$this->personInterface->expects($this->once())->method('save')->with($this->identicalTo($bob));

		$actor = $this->service->getFromId(self::BOB);

		$this->assertSame($bob, $actor);
		$this->assertSame('bob@remote.example', $actor->getAccount());
	}

	public function testGetFromIdWithRefreshSkipsTheCache(): void {
		$this->cacheActorsRequest->expects($this->never())->method('getFromId');
		$this->curlService->method('retrieveObject')->willReturn(['_host' => 'remote.example']);
		$this->ap->method('getItemFromData')->willReturn($this->person(self::BOB));

		$this->assertSame(self::BOB, $this->service->getFromId(self::BOB, true)->getId());
	}

	public function testGetFromIdRejectsADocumentThatIsNotAnActor(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')->willReturn(['type' => 'Note']);
		$this->ap->method('getItemFromData')->willReturn(new Note());
		$this->personInterface->expects($this->never())->method('save');

		$this->expectException(InvalidResourceException::class);
		$this->service->getFromId(self::BOB);
	}

	public function testGetFromIdRejectsAnActorClaimingAnotherHost(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')->willReturn(['_host' => 'remote.example']);
		$this->ap->method('getItemFromData')->willReturn($this->person('https://evil.example/users/bob'));
		$this->personInterface->expects($this->never())->method('save');

		$this->expectException(InvalidOriginException::class);
		$this->service->getFromId(self::BOB);
	}

	public function testGetFromIdWrapsSaveFailures(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')->willReturn(['_host' => 'remote.example']);
		$this->ap->method('getItemFromData')->willReturn($this->person(self::BOB));
		$this->personInterface->method('save')->willThrowException(new ItemAlreadyExistsException('dup'));

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessage('dup');
		$this->service->getFromId(self::BOB);
	}

	public function testRefreshOfAGoneActorDeletesTheCachedCopy(): void {
		$cached = $this->person(self::BOB);
		$this->cacheActorsRequest->expects($this->once())->method('getFromId')->with(self::BOB)->willReturn($cached);
		$this->curlService->method('retrieveObject')->willThrowException(new RequestContentException('gone', 410));
		$this->personInterface->expects($this->once())->method('delete')->with($this->identicalTo($cached));

		$this->expectException(RequestContentException::class);
		$this->service->getFromId(self::BOB, true);
	}

	public function testFetchFailureWithoutRefreshIsPropagatedWithoutDeleting(): void {
		$this->cacheActorsRequest->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveObject')->willThrowException(new RequestContentException('gone', 410));
		$this->personInterface->expects($this->never())->method('delete');

		$this->expectException(RequestContentException::class);
		$this->service->getFromId(self::BOB);
	}

	/** @return array<string, array{string}> */
	public static function localAccountProvider(): array {
		return [
			'bare username' => ['alice'],
			'leading at' => ['@alice'],
			'cloud host' => ['alice@cloud.example.com'],
			'social address' => ['@alice@social.example.com'],
		];
	}

	#[DataProvider('localAccountProvider')]
	public function testGetFromLocalAccountResolvesLocalHandles(string $account): void {
		$this->actorsRequest->expects($this->once())->method('getFromUsername')->with('alice')->willReturn($this->person(self::ALICE, 'alice'));
		$cached = $this->person(self::ALICE, 'alice');
		$this->cacheActorsRequest->expects($this->once())->method('getFromLocalAccount')->with('alice')->willReturn($cached);

		$this->assertSame($cached, $this->service->getFromLocalAccount($account));
	}

	public function testGetFromLocalAccountRejectsRemoteHandles(): void {
		$this->actorsRequest->expects($this->never())->method('getFromUsername');

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->expectExceptionMessage('not local');
		$this->service->getFromLocalAccount('bob@remote.example');
	}

	public function testGetFromLocalAccountRejectsDeletedAccounts(): void {
		$deleted = $this->person(self::ALICE, 'alice');
		$deleted->setDeleted(time() - 60);
		$this->actorsRequest->method('getFromUsername')->willReturn($deleted);
		$this->cacheActorsRequest->expects($this->never())->method('getFromLocalAccount');

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->expectExceptionMessage('deleted');
		$this->service->getFromLocalAccount('alice');
	}

	public function testGetFromAccountPrefersALocalAccount(): void {
		$this->actorsRequest->method('getFromUsername')->willReturn($this->person(self::ALICE, 'alice'));
		$alice = $this->person(self::ALICE, 'alice');
		$this->cacheActorsRequest->method('getFromLocalAccount')->willReturn($alice);
		$this->cacheActorsRequest->expects($this->never())->method('getFromAccount');
		$this->curlService->expects($this->never())->method('retrieveAccount');

		$this->assertSame($alice, $this->service->getFromAccount('alice@cloud.example.com'));
	}

	public function testGetFromAccountReturnsACachedRemoteActor(): void {
		$bob = $this->person(self::BOB);
		$this->cacheActorsRequest->expects($this->once())->method('getFromAccount')->with('bob@remote.example')->willReturn($bob);
		$this->curlService->expects($this->never())->method('retrieveAccount');

		$this->assertSame($bob, $this->service->getFromAccount('bob@remote.example'));
	}

	public function testGetFromAccountWebfingersAndCachesAnUnknownRemoteActor(): void {
		$this->cacheActorsRequest->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$bob = $this->person(self::BOB);
		$this->curlService->expects($this->once())->method('retrieveAccount')->with('bob@remote.example')->willReturn($bob);
		$this->personInterface->expects($this->once())->method('save')->with($this->identicalTo($bob));

		$actor = $this->service->getFromAccount('bob@remote.example');

		$this->assertSame($bob, $actor);
		$this->assertSame('bob@remote.example', $actor->getAccount());
	}

	public function testGetFromAccountWithoutRetrieveFailsOnCacheMiss(): void {
		$this->cacheActorsRequest->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->expects($this->never())->method('retrieveAccount');

		$this->expectException(CacheActorDoesNotExistException::class);
		$this->service->getFromAccount('bob@remote.example', false);
	}

	public function testGetFromAccountWrapsSaveFailures(): void {
		$this->cacheActorsRequest->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->curlService->method('retrieveAccount')->willReturn($this->person(self::BOB));
		$this->personInterface->method('save')->willThrowException(new ItemAlreadyExistsException('dup'));

		$this->expectException(InvalidResourceException::class);
		$this->service->getFromAccount('bob@remote.example');
	}

	public function testDelegatingLookups(): void {
		$bob = $this->person(self::BOB);
		$options = new ProbeOptions();
		$this->cacheActorsRequest->expects($this->once())->method('searchAccounts')->with('bo')->willReturn([$bob]);
		$this->cacheActorsRequest->expects($this->once())->method('getFromNids')->with([1, 2])->willReturn([$bob]);
		$this->cacheActorsRequest->expects($this->once())->method('probeActors')->with($this->identicalTo($options))->willReturn([$bob]);
		$this->cacheActorsRequest->expects($this->once())->method('setViewer')->with($this->identicalTo($bob));

		$this->assertSame([$bob], $this->service->searchCachedAccounts('bo'));
		$this->assertSame([$bob], $this->service->getFromNids([1, 2]));
		$this->assertSame([$bob], $this->service->probeActors($options));
		$this->service->setViewer($bob);
	}

	public function testMissingCacheRemoteActorsIsNotImplemented(): void {
		$this->assertSame(0, $this->service->missingCacheRemoteActors());
	}

	public function testManageCacheRemoteActorsRefreshesEachStaleActorAndSurvivesFailures(): void {
		$stale = [$this->person(self::BOB), $this->person('https://other.example/users/carol', 'carol')];
		$this->cacheActorsRequest->expects($this->once())->method('getRemoteActorsToUpdate')->with(true)->willReturn($stale);
		$retrieved = [];
		$this->curlService->expects($this->exactly(2))
			->method('retrieveObject')
			->willReturnCallback(function (string $id) use (&$retrieved) {
				$retrieved[] = $id;
				if ($id === self::BOB) {
					return ['_host' => 'remote.example'];
				}
				throw new RequestNetworkException('down');
			});
		$this->ap->method('getItemFromData')->willReturn($this->person(self::BOB));
		$this->personInterface->expects($this->once())->method('save');

		$this->assertSame(2, $this->service->manageCacheRemoteActors(true));
		$this->assertSame([self::BOB, 'https://other.example/users/carol'], $retrieved);
	}

	public function testAddRemoteActorDetailCountReadsTheCollectionTotals(): void {
		$bob = $this->person(self::BOB);
		$bob->setFollowers(self::BOB . '/followers');
		$bob->setFollowing(self::BOB . '/following');
		$bob->setOutbox(self::BOB . '/outbox');
		$totals = [self::BOB . '/followers' => 12, self::BOB . '/following' => 7, self::BOB . '/outbox' => 340];
		$this->curlService->method('retrieveObject')->willReturnCallback(fn (string $id) => ['type' => 'OrderedCollection', 'id' => $id]);
		$this->ap->method('getItemFromData')->willReturnCallback(function (array $data) use ($totals) {
			$collection = new OrderedCollection();
			$collection->setTotalItems($totals[$data['id']]);

			return $collection;
		});

		$this->service->addRemoteActorDetailCount($bob);

		$this->assertSame(['followers' => 12, 'following' => 7, 'post' => 340], $bob->getDetails('count'));
	}

	public function testAddRemoteActorDetailCountGivesUpWhenACollectionIsUnreachable(): void {
		$bob = $this->person(self::BOB);
		$bob->setFollowers(self::BOB . '/followers');
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException('down'));

		$this->service->addRemoteActorDetailCount($bob);

		$this->assertSame([], $bob->getDetails('count'));
	}

	public function testAddRemoteActorDetailCountRejectsNonCollections(): void {
		$bob = $this->person(self::BOB);
		$bob->setFollowers(self::BOB . '/followers');
		$this->curlService->method('retrieveObject')->willReturn(['type' => 'Note']);
		$this->ap->method('getItemFromData')->willReturn(new Note());

		$this->service->addRemoteActorDetailCount($bob);

		$this->assertSame([], $bob->getDetails('count'));
	}

	public function testManageDetailsRemoteActorsUpdatesEachActor(): void {
		$bob = $this->person(self::BOB);
		$this->cacheActorsRequest->method('getRemoteActorsToUpdateDetails')->with(false)->willReturn([$bob]);
		$this->curlService->method('retrieveObject')->willThrowException(new RequestNetworkException('down'));
		$this->cacheActorsRequest->expects($this->once())->method('updateDetails')->with($this->identicalTo($bob));

		$this->assertSame(1, $this->service->manageDetailsRemoteActors());
	}
}
