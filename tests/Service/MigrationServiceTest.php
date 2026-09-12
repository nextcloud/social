<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Move;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\SignatureService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class MigrationServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const NEW_ALICE = 'https://new.example/users/alice';
	private const BOB = 'https://cloud.example/apps/social/@bob';
	private const CAROL = 'https://remote.example/users/carol';

	private AccountService|MockObject $accountService;
	private ActorsRequest|MockObject $actorsRequest;
	private FollowsRequest|MockObject $followsRequest;
	private CacheActorService|MockObject $cacheActorService;
	private FollowService|MockObject $followService;
	private ActivityService|MockObject $activityService;
	private SignatureService|MockObject $signatureService;
	private MigrationService $service;

	protected function setUp(): void {
		$this->accountService = $this->createMock(AccountService::class);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->followService = $this->createMock(FollowService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->signatureService = $this->createMock(SignatureService::class);

		$this->service = new MigrationService(
			$this->accountService,
			$this->actorsRequest,
			$this->followsRequest,
			$this->cacheActorService,
			$this->followService,
			$this->activityService,
			$this->signatureService,
			new NullLogger(),
		);
	}

	private function person(string $id, string $account, bool $local = false): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername(explode('@', $account)[0])
			->setAccount($account)
			->setInbox($id . '/inbox')
			->setFollowers($id . '/followers')
			->setLocal($local);

		return $person;
	}

	private function alice(): Person {
		return $this->person(self::ALICE, 'alice@cloud.example', true);
	}

	private function newAlice(bool $listsAlice = true): Person {
		$target = $this->person(self::NEW_ALICE, 'alice@new.example');
		if ($listsAlice) {
			$target->setAlsoKnownAs([self::ALICE]);
		}

		return $target;
	}

	private function follow(string $actorId): Follow {
		$follow = new Follow();
		$follow->setId('https://cloud.example/apps/social/follow/' . md5($actorId));
		$follow->setActorId($actorId);
		$follow->setObjectId(self::ALICE);
		$follow->setAccepted(true);

		return $follow;
	}

	// --- aliases ----------------------------------------------------------

	public function testAddAliasAppendsToTheListOnce(): void {
		$alice = $this->alice();
		$alice->setAlsoKnownAs(['https://old.example/users/alice']);
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($alice);
		$this->accountService->expects($this->once())->method('setAlsoKnownAs')
			->with('alice', ['https://old.example/users/alice', 'https://older.example/users/alice']);

		$aliases = $this->service->addAlias('alice', 'https://older.example/users/alice');

		$this->assertSame(['https://old.example/users/alice', 'https://older.example/users/alice'], $aliases);
	}

	public function testAddAliasThatIsAlreadyListedChangesNothing(): void {
		$alice = $this->alice();
		$alice->setAlsoKnownAs(['https://old.example/users/alice']);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->accountService->expects($this->never())->method('setAlsoKnownAs');

		$aliases = $this->service->addAlias('alice', 'https://old.example/users/alice');

		$this->assertSame(['https://old.example/users/alice'], $aliases);
	}

	#[DataProvider('notAnActorIdProvider')]
	public function testAddAliasRefusesWhatIsNotAnActorId(string $alias): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->accountService->expects($this->never())->method('setAlsoKnownAs');

		$this->expectException(InvalidResourceException::class);
		$this->service->addAlias('alice', $alias);
	}

	public static function notAnActorIdProvider(): array {
		return [
			'a handle' => ['alice@old.example'],
			'empty' => [''],
			'no host' => ['https:///users/alice'],
			'not http' => ['ftp://old.example/users/alice'],
			'itself' => [self::ALICE],
		];
	}

	public function testRemoveAliasDropsItFromTheList(): void {
		$alice = $this->alice();
		$alice->setAlsoKnownAs(['https://old.example/users/alice', 'https://older.example/users/alice']);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->accountService->expects($this->once())->method('setAlsoKnownAs')
			->with('alice', ['https://older.example/users/alice']);

		$aliases = $this->service->removeAlias('alice', 'https://old.example/users/alice');

		$this->assertSame(['https://older.example/users/alice'], $aliases);
	}

	public function testRemoveAliasThatIsNotListedChangesNothing(): void {
		$alice = $this->alice();
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->accountService->expects($this->never())->method('setAlsoKnownAs');

		$this->assertSame([], $this->service->removeAlias('alice', 'https://old.example/users/alice'));
	}

	public function testListAliases(): void {
		$alice = $this->alice();
		$alice->setAlsoKnownAs(['https://old.example/users/alice']);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);

		$this->assertSame(['https://old.example/users/alice'], $this->service->listAliases('alice'));
	}

	// --- move -----------------------------------------------------------------

	public function testMoveFederatesAMoveToTheFollowersAndRecordsTheTarget(): void {
		$alice = $this->alice();
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($alice);
		// the target is fetched fresh: the alsoKnownAs that counts is the one its server publishes now
		$this->cacheActorService->expects($this->once())->method('getFromId')
			->with(self::NEW_ALICE, true)->willReturn($this->newAlice());
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);

		$sent = null;
		$this->signatureService->expects($this->once())->method('signObject')
			->with($this->identicalTo($alice), $this->isInstanceOf(Move::class));
		$this->activityService->expects($this->once())->method('request')
			->willReturnCallback(function (ACore $activity) use (&$sent): string {
				$sent = $activity;

				return 'token';
			});
		$this->accountService->expects($this->once())->method('setMovedTo')->with('alice', self::NEW_ALICE);

		$target = $this->service->move('alice', self::NEW_ALICE);

		$this->assertSame(self::NEW_ALICE, $target->getId());
		$this->assertInstanceOf(Move::class, $sent);
		$document = json_decode(json_encode($sent), true);
		$this->assertSame('Move', $document['type']);
		$this->assertSame(self::ALICE, $document['actor']);
		$this->assertSame(self::ALICE, $document['object'], 'the account that moves');
		$this->assertSame(self::NEW_ALICE, $document['target'], 'where it moved to — without it the Move says nothing');
		$this->assertStringStartsWith(self::ALICE . '#', $document['id']);

		$paths = array_map(
			static fn (InstancePath $path): array => [$path->getUri(), $path->getType()],
			$sent->getInstancePaths()
		);
		$this->assertContains([self::ALICE, InstancePath::TYPE_FOLLOWERS], $paths, 'fanned out to every follower inbox');
		$this->assertContains([self::NEW_ALICE . '/inbox', InstancePath::TYPE_INBOX], $paths, 'and told to the new home');
	}

	public function testMoveRefusesATargetThatDoesNotListTheActor(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->cacheActorService->method('getFromId')->willReturn($this->newAlice(false));
		$this->activityService->expects($this->never())->method('request');
		$this->accountService->expects($this->never())->method('setMovedTo');

		$this->expectException(InvalidResourceException::class);
		$this->expectExceptionMessageMatches('/alsoKnownAs/');
		$this->service->move('alice', self::NEW_ALICE);
	}

	public function testMoveRefusesToMoveAnAccountOntoItself(): void {
		$alice = $this->alice();
		$alice->setAlsoKnownAs([self::ALICE]);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->cacheActorService->method('getFromId')->willReturn($alice);
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidResourceException::class);
		$this->service->move('alice', self::ALICE);
	}

	public function testMoveRecordsTheTargetOnlyAfterTheMoveWasQueued(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->cacheActorService->method('getFromId')->willReturn($this->newAlice());
		$this->activityService->method('request')->willThrowException(new RuntimeException('queue is down'));
		$this->accountService->expects($this->never())->method('setMovedTo');

		$this->expectException(RuntimeException::class);
		$this->service->move('alice', self::NEW_ALICE);
	}

	/**
	 * A follower on this very instance never receives the Move — deliveries
	 * to ourselves are dropped — so nothing would re-follow on their behalf.
	 * Mastodon re-follows for its users on receiving a Move; so does this, for
	 * the local ones, through the ordinary follow path.
	 */
	public function testMoveRefollowsTheLocalFollowersAndLeavesRemoteOnesToTheirServer(): void {
		$alice = $this->alice();
		$bob = $this->person(self::BOB, 'bob@cloud.example', true);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->cacheActorService->method('getFromId')->willReturn($this->newAlice());
		$this->followsRequest->method('getFollowersByActorId')->with(self::ALICE)
			->willReturn([$this->follow(self::BOB), $this->follow(self::CAROL)]);
		$this->actorsRequest->method('getFromId')->willReturnCallback(function (string $id) use ($bob): Person {
			if ($id === self::BOB) {
				return $bob;
			}
			throw new ActorDoesNotExistException();
		});
		$this->followService->expects($this->once())->method('followAccount')
			->with($this->identicalTo($bob), 'alice@new.example');

		$this->service->move('alice', self::NEW_ALICE);
	}

	public function testOneLocalFollowerThatCannotRefollowDoesNotStopTheOthers(): void {
		$alice = $this->alice();
		$bob = $this->person(self::BOB, 'bob@cloud.example', true);
		$dave = $this->person('https://cloud.example/apps/social/@dave', 'dave@cloud.example', true);
		$this->accountService->method('getActorFromUserId')->willReturn($alice);
		$this->cacheActorService->method('getFromId')->willReturn($this->newAlice());
		$this->followsRequest->method('getFollowersByActorId')
			->willReturn([$this->follow($bob->getId()), $this->follow($dave->getId())]);
		$this->actorsRequest->method('getFromId')->willReturnCallback(
			static fn (string $id): Person => $id === $bob->getId() ? $bob : $dave
		);
		$calls = 0;
		$this->followService->method('followAccount')->willReturnCallback(function () use (&$calls): void {
			$calls++;
			if ($calls === 1) {
				throw new RuntimeException('unreachable');
			}
		});

		$this->service->move('alice', self::NEW_ALICE);

		$this->assertSame(2, $calls);
	}

	// --- follows CSV ------------------------------------------------------------

	private const MASTODON_CSV = "Account address,Show boosts,Notify on new posts,Languages\n"
		. "carol@remote.example,true,false,\n"
		. "dave@other.example,false,false,\"en, de\"\n"
		. "\n"
		. "@erin@third.example,true,false,\n";

	public function testParseFollowsCsvReadsTheMastodonExport(): void {
		$this->assertSame(
			['carol@remote.example', 'dave@other.example', 'erin@third.example'],
			MigrationService::parseFollowsCsv(self::MASTODON_CSV)
		);
	}

	public function testParseFollowsCsvReadsTheOlderHeaderlessExport(): void {
		$this->assertSame(
			['carol@remote.example', 'dave@other.example'],
			MigrationService::parseFollowsCsv("carol@remote.example\r\ndave@other.example\r\n")
		);
	}

	public function testParseFollowsCsvFindsTheAddressColumnWhereverItIs(): void {
		$csv = "Show boosts,Account address\ntrue,carol@remote.example\n";

		$this->assertSame(['carol@remote.example'], MigrationService::parseFollowsCsv($csv));
	}

	public function testParseFollowsCsvDropsDuplicatesAndJunk(): void {
		$csv = "Account address\ncarol@remote.example\nCarol@Remote.example\nnot a handle\n  \n";

		$this->assertSame(['carol@remote.example'], MigrationService::parseFollowsCsv($csv));
	}

	/**
	 * A fediverse host is usually dotted, but an instance reached as `devel`
	 * or `cloud` on a private network is not, and insisting on a dot dropped
	 * every handle on such a server — including the ones in this app's own
	 * export.
	 */
	public function testParseFollowsCsvKeepsAHandleOnASingleLabelHost(): void {
		$csv = "Account address\nerik@devel\nhana@cloud\n";

		$this->assertSame(['erik@devel', 'hana@cloud'], MigrationService::parseFollowsCsv($csv));
	}

	public function testImportFollowsFollowsEachHandleAndCounts(): void {
		$alice = $this->alice();
		$this->accountService->method('getActorFromUserId')->with('alice')->willReturn($alice);
		$followed = [];
		$this->followService->method('followAccount')
			->willReturnCallback(function (Person $actor, string $account) use (&$followed): void {
				$followed[] = $account;
			});

		$result = $this->service->importFollows('alice', self::MASTODON_CSV);

		$this->assertSame(['carol@remote.example', 'dave@other.example', 'erin@third.example'], $followed);
		$this->assertSame(3, $result['followed']);
		$this->assertSame(0, $result['skipped']);
		$this->assertSame([], $result['failed']);
	}

	public function testImportFollowsContinuesPastAFailureAndReportsIt(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followService->method('followAccount')
			->willReturnCallback(static function (Person $actor, string $account): void {
				if ($account === 'dave@other.example') {
					throw new RuntimeException('instance unreachable');
				}
			});

		$result = $this->service->importFollows('alice', self::MASTODON_CSV);

		$this->assertSame(2, $result['followed']);
		$this->assertSame(['dave@other.example' => 'instance unreachable'], $result['failed']);
	}

	public function testImportFollowsSkipsTheImportingAccountItself(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followService->method('followAccount')
			->willReturnCallback(static function (Person $actor, string $account): void {
				if ($account === 'bob@cloud.example') {
					throw new FollowSameAccountException();
				}
			});

		$result = $this->service->importFollows(
			'alice',
			"Account address\nalice@cloud.example\nbob@cloud.example\ncarol@remote.example\n"
		);

		$this->assertSame(1, $result['followed']);
		$this->assertSame(2, $result['skipped'], 'our own handle, and the one the follow path says is ours');
		$this->assertSame([], $result['failed']);
	}

	public function testImportFollowsWithAnEmptyFileDoesNothing(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followService->expects($this->never())->method('followAccount');

		$this->assertSame(
			['followed' => 0, 'skipped' => 0, 'failed' => []],
			$this->service->importFollows('alice', "Account address,Show boosts,Notify on new posts,Languages\n")
		);
	}

	public function testExportFollowsCsvWritesTheHeaderMastodonWrites(): void {
		$csv = MigrationService::exportFollowsCsv(['carol@remote.example', 'dave@other.example']);

		$this->assertSame(
			"Account address,Show boosts,Notify on new posts,Languages\n"
			. "carol@remote.example,true,false,\n"
			. "dave@other.example,true,false,\n",
			$csv
		);
	}

	public function testExportFollowsCsvWithNoHandlesIsTheHeaderAlone(): void {
		$this->assertSame(
			"Account address,Show boosts,Notify on new posts,Languages\n",
			MigrationService::exportFollowsCsv([])
		);
	}

	/**
	 * The writer exists so that what leaves here can come back — into this app,
	 * or into Mastodon's "Import follows", which is the same file.
	 */
	public function testExportFollowsCsvRoundTripsThroughTheReader(): void {
		$handles = ['carol@remote.example', 'dave@other.example', 'erin@third.example'];

		$this->assertSame(
			$handles,
			MigrationService::parseFollowsCsv(MigrationService::exportFollowsCsv($handles))
		);
	}
}
