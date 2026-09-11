<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\UserMigration;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Tests\Helper\MigrationArchive;
use OCA\Social\Tests\Helper\RecordingOutput;
use OCA\Social\UserMigration\SocialMigrator;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SocialMigratorTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const CAROL = 'https://remote.example/users/carol';
	private const DAVE = 'https://other.example/users/dave';

	private AccountService|MockObject $accountService;
	private MigrationService|MockObject $migrationService;
	private CacheActorService|MockObject $cacheActorService;
	private FollowsRequest|MockObject $followsRequest;
	private ActorRelationRequest|MockObject $actorRelationRequest;
	private StreamRequest|MockObject $streamRequest;
	private StreamActionService|MockObject $streamActionService;
	private SocialMigrator $migrator;
	private RecordingOutput $output;

	/** @var string[] */
	private array $tempFiles = [];

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->accountService = $this->createMock(AccountService::class);
		$this->migrationService = $this->createMock(MigrationService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->followsRequest = $this->createMock(FollowsRequest::class);
		$this->actorRelationRequest = $this->createMock(ActorRelationRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->streamActionService = $this->createMock(StreamActionService::class);

		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = (string)tempnam(sys_get_temp_dir(), 'social-migrator-test');
			$this->tempFiles[] = $path;

			return $path;
		});

		$this->migrator = new SocialMigrator(
			$l10n,
			$this->accountService,
			$this->migrationService,
			$this->cacheActorService,
			$this->followsRequest,
			$this->actorRelationRequest,
			$this->streamRequest,
			$this->streamActionService,
			$tempManager,
			new NullLogger(),
		);
		$this->output = new RecordingOutput();
	}

	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
		$this->tempFiles = [];
	}

	private function user(string $uid = 'alice'): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	private function person(string $id, string $account): Person {
		$person = new Person();
		$person->setId($id)
			->setPreferredUsername(explode('@', $account)[0])
			->setAccount($account);

		return $person;
	}

	/** The local actor as it comes out of the database, private key and all. */
	private function alice(): Person {
		$alice = $this->person(self::ALICE, 'alice@cloud.example');
		$alice->setUserId('alice')
			->setName('Alice Liddell')
			->setDescription('<p>down the rabbit hole</p>')
			->setPublicKey('-----BEGIN PUBLIC KEY-----AAAA-----END PUBLIC KEY-----')
			->setPrivateKey('-----BEGIN RSA PRIVATE KEY-----SECRET-----END RSA PRIVATE KEY-----')
			->setLocked(true)
			->setDiscoverable(true)
			->setIndexable(false)
			->setBot(false)
			->setSensitive(false)
			->setPrivacy('unlisted')
			->setLanguage('en')
			->setAvatar('https://cloud.example/avatar.png')
			->setHeader('https://cloud.example/header.png')
			->setFields([['name' => 'Website', 'value' => 'https://alice.example']])
			->setAlsoKnownAs(['https://old.example/users/alice'])
			->setCreation(1700000000);

		return $alice;
	}

	private function follow(string $objectId, string $account): Follow {
		$follow = new Follow();
		$follow->setActorId(self::ALICE);
		$follow->setObjectId($objectId);
		$follow->setActor($this->person($objectId, $account));

		return $follow;
	}

	private function follower(string $actorId, string $account): Follow {
		$follow = new Follow();
		$follow->setActorId($actorId);
		$follow->setObjectId(self::ALICE);
		$follow->setActor($this->person($actorId, $account));

		return $follow;
	}

	private function relation(string $objectId, string $type, bool $notifications = true): ActorRelation {
		$relation = new ActorRelation();
		$relation->setActorIdPrim('alice')
			->setObjectId($objectId)
			->setType($type)
			->setNotifications($notifications);

		return $relation;
	}

	private function note(string $id, int $nid, string $content): Note {
		$note = new Note();
		$note->setId($id);
		$note->setNid($nid);
		$note->setContent($content);
		$note->setAttributedTo(self::ALICE);
		$note->setPublished('2026-01-0' . $nid . 'T00:00:00Z');

		return $note;
	}

	/** The actor exists and every collection around it is empty. */
	private function anAccountWithNothingInIt(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followsRequest->method('getFollowingByActorId')->willReturn([]);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);
		$this->actorRelationRequest->method('getByActor')->willReturn([]);
		$this->streamRequest->method('getTimeline')->willReturn([]);
	}

	private function export(): MigrationArchive {
		$archive = new MigrationArchive();
		$this->migrator->export($this->user(), $archive, $this->output);

		return $archive;
	}

	public function testItIsTheSocialMigrator(): void {
		$this->assertSame('social', $this->migrator->getId());
		$this->assertNotSame('', $this->migrator->getDisplayName());
		$this->assertNotSame('', $this->migrator->getDescription());
		$this->assertSame(1, $this->migrator->getVersion());
	}

	public function testAUserWithNoSocialAccountExportsNothingAndIsNotAnError(): void {
		$this->accountService->method('getActorFromUserId')
			->willThrowException(new ActorDoesNotExistException('no actor'));

		$archive = $this->export();

		$this->assertSame([], $archive->paths());
		$this->assertStringContainsString('no Social account', $this->output->text());
	}

	public function testTheArchiveHoldsExactlyTheFilesThisMigratorKnowsAbout(): void {
		$this->anAccountWithNothingInIt();

		$this->assertSame([
			'social/actor.json',
			'social/blocked_accounts.csv',
			'social/bookmarks.csv',
			'social/followers.csv',
			'social/following_accounts.csv',
			'social/likes.csv',
			'social/muted_accounts.csv',
			'social/outbox.json',
		], $this->export()->paths());
	}

	public function testTheActorFileCarriesTheIdentityAndTheProfile(): void {
		$this->anAccountWithNothingInIt();

		$actor = json_decode($this->export()->contents('social/actor.json'), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(self::ALICE, $actor['id']);
		$this->assertSame('alice@cloud.example', $actor['account']);
		$this->assertSame('alice', $actor['preferredUsername']);
		$this->assertSame('Alice Liddell', $actor['name']);
		$this->assertSame('<p>down the rabbit hole</p>', $actor['summary']);
		$this->assertSame([['name' => 'Website', 'value' => 'https://alice.example']], $actor['fields']);
		$this->assertTrue($actor['locked']);
		$this->assertTrue($actor['discoverable']);
		$this->assertFalse($actor['indexable']);
		$this->assertSame('unlisted', $actor['privacy']);
		$this->assertSame(['https://old.example/users/alice'], $actor['alsoKnownAs']);
		$this->assertSame('https://cloud.example/header.png', $actor['header']);
		$this->assertStringContainsString('BEGIN PUBLIC KEY', $actor['publicKey']);
	}

	/**
	 * An export archive is an ordinary file the user downloads, keeps and
	 * copies around. The private key of an actor is the one secret that lets
	 * anything speak as that account, for ever and with no way to revoke it,
	 * and the app goes as far as encrypting it in the database — so it does not
	 * leave here in the clear, in any file, under any key name.
	 */
	public function testThePrivateKeyIsInNoFileOfTheArchive(): void {
		$this->anAccountWithNothingInIt();

		foreach ($this->export()->files() as $path => $content) {
			$this->assertStringNotContainsString('PRIVATE KEY', $content, $path . ' carries a private key');
			$this->assertStringNotContainsString('SECRET', $content, $path . ' carries a private key');
		}

		$actor = json_decode($this->export()->contents('social/actor.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertArrayNotHasKey('privateKey', $actor);
		$this->assertArrayNotHasKey('privateKeyPem', $actor);
	}

	public function testTheFollowsAreWrittenAsAMastodonFollowingAccountsCsv(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followsRequest->method('getFollowingByActorId')
			->willReturnCallback(fn (string $actorId, int $limit, int $offset): array => $offset === 0
				? [$this->follow(self::CAROL, 'carol@remote.example'), $this->follow(self::DAVE, 'dave@other.example')]
				: []);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);
		$this->actorRelationRequest->method('getByActor')->willReturn([]);
		$this->streamRequest->method('getTimeline')->willReturn([]);

		$csv = $this->export()->contents('social/following_accounts.csv');

		$this->assertSame(
			MigrationService::exportFollowsCsv(['carol@remote.example', 'dave@other.example']),
			$csv
		);
		$this->assertSame(
			['carol@remote.example', 'dave@other.example'],
			MigrationService::parseFollowsCsv($csv),
			'what we write has to be what the reader of this app, and Mastodon, accepts'
		);
	}

	public function testTheFollowsAreReadInPagesRatherThanAllAtOnce(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$offsets = [];
		$this->followsRequest->method('getFollowingByActorId')
			->willReturnCallback(function (string $actorId, int $limit, int $offset) use (&$offsets): array {
				$offsets[] = $offset;
				$this->assertGreaterThan(0, $limit, 'an unbounded read is the thing being avoided');

				return $offset === 0
					? array_fill(0, $limit, $this->follow(self::CAROL, 'carol@remote.example'))
					: [];
			});
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);
		$this->actorRelationRequest->method('getByActor')->willReturn([]);
		$this->streamRequest->method('getTimeline')->willReturn([]);

		$this->export();

		$this->assertSame([0, SocialMigrator::PAGE], $offsets);
	}

	public function testTheFollowersAreWrittenTooButOnlyToRead(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followsRequest->method('getFollowingByActorId')->willReturn([]);
		$this->followsRequest->method('getFollowersByActorId')
			->willReturnCallback(fn (string $actorId, int $limit, int $offset): array => $offset === 0
				? [$this->follower(self::CAROL, 'carol@remote.example')]
				: []);
		$this->actorRelationRequest->method('getByActor')->willReturn([]);
		$this->streamRequest->method('getTimeline')->willReturn([]);

		$this->assertSame(
			['carol@remote.example'],
			MigrationService::parseFollowsCsv($this->export()->contents('social/followers.csv'))
		);
	}

	public function testAFollowWhoseAccountIsNotCachedIsLeftOutRatherThanWrittenAsAUrl(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$unknown = new Follow();
		$unknown->setActorId(self::ALICE);
		$unknown->setObjectId('https://gone.example/users/nobody');
		$this->followsRequest->method('getFollowingByActorId')
			->willReturnCallback(fn (string $actorId, int $limit, int $offset): array => $offset === 0
				? [$unknown, $this->follow(self::CAROL, 'carol@remote.example')]
				: []);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);
		$this->actorRelationRequest->method('getByActor')->willReturn([]);
		$this->streamRequest->method('getTimeline')->willReturn([]);

		$this->assertSame(
			['carol@remote.example'],
			MigrationService::parseFollowsCsv($this->export()->contents('social/following_accounts.csv'))
		);
	}

	public function testBlocksAndMutesAreWrittenInTheShapeMastodonExportsThem(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followsRequest->method('getFollowingByActorId')->willReturn([]);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);
		$this->actorRelationRequest->method('getByActor')
			->willReturnCallback(fn (string $actorId, string $type): array => match ($type) {
				ActorRelation::TYPE_BLOCK => [$this->relation(self::CAROL, ActorRelation::TYPE_BLOCK)],
				ActorRelation::TYPE_MUTE => [$this->relation(self::DAVE, ActorRelation::TYPE_MUTE, false)],
				default => [],
			});
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(fn (string $id): Person => match ($id) {
				self::CAROL => $this->person(self::CAROL, 'carol@remote.example'),
				self::DAVE => $this->person(self::DAVE, 'dave@other.example'),
				default => throw new CacheActorDoesNotExistException($id),
			});
		$this->streamRequest->method('getTimeline')->willReturn([]);

		$archive = $this->export();

		$this->assertSame("carol@remote.example\n", $archive->contents('social/blocked_accounts.csv'));
		$this->assertSame(
			"Account address,Hide notifications\ndave@other.example,true\n",
			$archive->contents('social/muted_accounts.csv')
		);
	}

	public function testABlockedAccountThatCannotBeResolvedToAHandleIsSkipped(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followsRequest->method('getFollowingByActorId')->willReturn([]);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);
		$this->actorRelationRequest->method('getByActor')
			->willReturnCallback(fn (string $actorId, string $type): array => $type === ActorRelation::TYPE_BLOCK
				? [$this->relation('https://gone.example/users/nobody', ActorRelation::TYPE_BLOCK)]
				: []);
		$this->cacheActorService->method('getFromId')
			->willThrowException(new CacheActorDoesNotExistException('gone'));
		$this->streamRequest->method('getTimeline')->willReturn([]);

		$this->assertSame('', $this->export()->contents('social/blocked_accounts.csv'));
	}

	public function testTheOwnPostsAreStreamedAsAnOrderedCollection(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followsRequest->method('getFollowingByActorId')->willReturn([]);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);
		$this->actorRelationRequest->method('getByActor')->willReturn([]);
		$this->streamRequest->expects($this->atLeastOnce())->method('setViewer');
		$this->streamRequest->method('getTimeline')
			->willReturnCallback(function (ProbeOptions $options): array {
				if ($options->getProbe() !== ProbeOptions::ACCOUNT || $options->getMaxId() > 0) {
					return [];
				}

				return [$this->note('https://cloud.example/1', 2, 'newer'), $this->note('https://cloud.example/2', 1, 'older')];
			});

		$outbox = json_decode($this->export()->contents('social/outbox.json'), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame('OrderedCollection', $outbox['type']);
		$this->assertSame('https://www.w3.org/ns/activitystreams', $outbox['@context']);
		$this->assertSame(2, $outbox['totalItems']);
		$this->assertSame(
			['https://cloud.example/1', 'https://cloud.example/2'],
			array_column($outbox['orderedItems'], 'id')
		);
		$this->assertArrayNotHasKey(
			'@context',
			$outbox['orderedItems'][0],
			'the collection carries the context once; repeating it per item bloats the archive'
		);
	}

	public function testTheOwnPostsAreReadInPagesRatherThanAllAtOnce(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followsRequest->method('getFollowingByActorId')->willReturn([]);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);
		$this->actorRelationRequest->method('getByActor')->willReturn([]);

		$maxIds = [];
		$this->streamRequest->method('getTimeline')
			->willReturnCallback(function (ProbeOptions $options) use (&$maxIds): array {
				if ($options->getProbe() !== ProbeOptions::ACCOUNT) {
					return [];
				}

				$maxIds[] = $options->getMaxId();

				return match (count($maxIds)) {
					1 => [$this->note('https://cloud.example/1', 9, 'a'), $this->note('https://cloud.example/2', 5, 'b')],
					2 => [$this->note('https://cloud.example/3', 3, 'c')],
					default => [],
				};
			});

		$outbox = json_decode($this->export()->contents('social/outbox.json'), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame([0, 5, 3], $maxIds, 'each page starts below the last post of the one before');
		$this->assertSame(3, $outbox['totalItems']);
	}

	public function testBookmarksAndFavouritesAreWrittenAsPostUrls(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followsRequest->method('getFollowingByActorId')->willReturn([]);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);
		$this->actorRelationRequest->method('getByActor')->willReturn([]);
		$this->streamRequest->method('getTimeline')
			->willReturnCallback(function (ProbeOptions $options): array {
				if ($options->getMaxId() > 0) {
					return [];
				}

				return match ($options->getProbe()) {
					ProbeOptions::BOOKMARKS => [$this->note('https://remote.example/notes/7', 4, 'kept')],
					ProbeOptions::FAVOURITES => [$this->note('https://remote.example/notes/8', 3, 'liked')],
					default => [],
				};
			});

		$archive = $this->export();

		$this->assertSame("https://remote.example/notes/7\n", $archive->contents('social/bookmarks.csv'));
		$this->assertSame("https://remote.example/notes/8\n", $archive->contents('social/likes.csv'));
	}

	public function testTheEstimatedSizeGrowsWithWhatThereIsToExport(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->streamRequest->method('countNotesFromActorId')->willReturn(1000);
		$this->followsRequest->method('countFollowing')->willReturn(500);
		$this->followsRequest->method('countFollowers')->willReturn(500);

		$this->assertGreaterThan(
			0,
			$this->migrator->getEstimatedExportSize($this->user()),
			'a thousand posts is not a zero-sized export'
		);
	}

	public function testTheEstimatedSizeOfAnAccountThatDoesNotExistIsZero(): void {
		$this->accountService->method('getActorFromUserId')
			->willThrowException(new ActorDoesNotExistException('no actor'));

		$this->assertSame(0, $this->migrator->getEstimatedExportSize($this->user()));
	}

	// ---------------------------------------------------------------- import

	/** An archive this migrator wrote, as a fresh import source. */
	private function archiveOf(callable $fill): MigrationArchive {
		$archive = new MigrationArchive('alice');
		$archive->setVersion('social', 1);
		$fill($archive);

		return $archive;
	}

	private function actorFile(array $overrides = []): string {
		return (string)json_encode(array_merge([
			'id' => self::ALICE,
			'account' => 'alice@cloud.example',
			'preferredUsername' => 'alice',
			'name' => 'Alice Liddell',
			'summary' => 'down the rabbit hole',
			'fields' => [['name' => 'Website', 'value' => 'https://alice.example']],
			'locked' => true,
			'discoverable' => true,
			'indexable' => false,
			'publicKey' => '-----BEGIN PUBLIC KEY-----AAAA-----END PUBLIC KEY-----',
		], $overrides));
	}

	private function importing(MigrationArchive $archive): void {
		$this->migrator->import($this->user(), $archive, $this->output);
	}

	public function testAnArchiveWithoutAVersionForThisMigratorIsSkippedEntirely(): void {
		$archive = new MigrationArchive('alice');
		$archive->put('social/actor.json', $this->actorFile());
		$this->accountService->expects($this->never())->method('getActorFromUserId');

		$this->importing($archive);

		$this->assertStringContainsString('skipping', $this->output->text());
	}

	public function testAnArchiveWithoutAVersionCanStillBeImportedByTheFramework(): void {
		// the migrator is not mandatory: an archive from an installation
		// without this app must not fail the whole account import
		$this->assertTrue($this->migrator->canImport(new MigrationArchive('alice')));
	}

	public function testTheAccountIsCreatedWhenTheServerHasNoneForThisUser(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile());
		});
		$this->accountService->expects($this->once())
			->method('getActorFromUserId')
			->with('alice', true)
			->willReturn($this->alice());
		$this->accountService->expects($this->never())->method('createActor');

		$this->importing($archive);
	}

	public function testAnAccountThatCannotBeCreatedStopsThisMigratorAndNotTheAccountImport(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile());
			$a->put('social/following_accounts.csv', "Account address\ncarol@remote.example\n");
		});
		$this->accountService->method('getActorFromUserId')
			->willThrowException(new ActorDoesNotExistException('handle is not usable'));
		$this->migrationService->expects($this->never())->method('importFollows');

		$this->importing($archive);

		$this->assertStringContainsString('no Social account', $this->output->text());
	}

	public function testTheProfileFlagsAndFieldsAreRestored(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile());
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->accountService->expects($this->once())->method('setLocked')->with('alice', true);
		$this->accountService->expects($this->once())
			->method('setActorFlags')
			->with('alice', ['discoverable' => true, 'indexable' => false]);
		$this->accountService->expects($this->once())
			->method('setFields')
			->with('alice', [['name' => 'Website', 'value' => 'https://alice.example']]);
		$this->accountService->expects($this->once())
			->method('setSummary')
			->with('alice', 'down the rabbit hole');

		$this->importing($archive);
	}

	public function testAnArchiveWithAnEmptyBioDoesNotBlankTheBioThatIsHere(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile(['summary' => '']));
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->accountService->expects($this->never())->method('setSummary');

		$this->importing($archive);
	}

	/**
	 * The imported account is a new actor with a new id and a new key pair.
	 * What ties it to the old one is the alias: a remote server only accepts a
	 * `Move` towards an account that lists the mover in `alsoKnownAs`, so
	 * without this the old account cannot hand its followers over.
	 */
	public function testTheOldActorIdIsRecordedAsAnAlias(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile());
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->migrationService->expects($this->once())->method('addAlias')->with('alice', self::ALICE);

		$this->importing($archive);
	}

	public function testAnActorFileWithoutAnIdRecordsNoAlias(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile(['id' => '']));
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->migrationService->expects($this->never())->method('addAlias');

		$this->importing($archive);
	}

	public function testTheFollowsAreReCreatedThroughTheOrdinaryFollowPath(): void {
		$csv = MigrationService::exportFollowsCsv(['carol@remote.example', 'dave@other.example']);
		$archive = $this->archiveOf(function (MigrationArchive $a) use ($csv): void {
			$a->put('social/actor.json', $this->actorFile());
			$a->put('social/following_accounts.csv', $csv);
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->migrationService->expects($this->once())
			->method('importFollows')
			->with('alice', $csv)
			->willReturn(['followed' => 2, 'skipped' => 0, 'failed' => []]);

		$this->importing($archive);

		$this->assertStringContainsString('2', $this->output->text());
	}

	public function testTheFollowersFileIsReadByNobodyOnImport(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile());
			$a->put('social/followers.csv', MigrationService::exportFollowsCsv(['carol@remote.example']));
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		// a follower is somebody else's decision: this account cannot make it again
		$this->migrationService->expects($this->never())->method('importFollows');

		$this->importing($archive);
	}

	public function testBlocksAndMutesAreRestoredLocallyWithoutTellingAnybody(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile());
			$a->put('social/blocked_accounts.csv', "carol@remote.example\n");
			$a->put('social/muted_accounts.csv', "Account address,Hide notifications\ndave@other.example,true\n");
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(fn (string $account): Person => match ($account) {
				'carol@remote.example' => $this->person(self::CAROL, $account),
				'dave@other.example' => $this->person(self::DAVE, $account),
				default => throw new CacheActorDoesNotExistException($account),
			});

		$saved = [];
		$this->actorRelationRequest->method('save')
			->willReturnCallback(function (string $actorId, string $objectId, string $type, bool $notifications = true) use (&$saved): void {
				$saved[] = [$actorId, $objectId, $type, $notifications];
			});

		$this->importing($archive);

		$this->assertSame([
			[self::ALICE, self::CAROL, ActorRelation::TYPE_BLOCK, true],
			[self::ALICE, self::DAVE, ActorRelation::TYPE_MUTE, false],
		], $saved, 'the mute hid notifications, so the relation must not notify');
	}

	public function testABlockedAccountThatCannotBeResolvedIsReportedAndTheRestStillLand(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile());
			$a->put('social/blocked_accounts.csv', "gone@nowhere.example\ncarol@remote.example\n");
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(fn (string $account): Person => $account === 'carol@remote.example'
				? $this->person(self::CAROL, $account)
				: throw new CacheActorDoesNotExistException($account));

		$saved = [];
		$this->actorRelationRequest->method('save')
			->willReturnCallback(function (string $actorId, string $objectId, string $type) use (&$saved): void {
				$saved[] = $objectId;
			});

		$this->importing($archive);

		$this->assertSame([self::CAROL], $saved);
	}

	public function testBookmarksAndFavouritesAreMarkedOnThePostsThisServerAlreadyHas(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile());
			$a->put('social/bookmarks.csv', "https://remote.example/notes/7\nhttps://gone.example/notes/8\n");
			$a->put('social/likes.csv', "https://remote.example/notes/9\n");
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) {
				if ($id === 'https://gone.example/notes/8') {
					throw new StreamNotFoundException($id);
				}

				return $this->note($id, 1, 'known');
			});

		$marks = [];
		$this->streamActionService->method('setActionBool')
			->willReturnCallback(function (string $actorId, string $streamId, string $key, bool $value) use (&$marks): void {
				$marks[] = [$streamId, $key, $value];
			});

		$this->importing($archive);

		$this->assertSame([
			['https://remote.example/notes/7', StreamAction::BOOKMARKED, true],
			['https://remote.example/notes/9', StreamAction::LIKED, true],
		], $marks, 'a post this server never saw cannot be marked, and is not fetched to be marked');
	}

	/**
	 * The posts are in the archive because they are the user's, not because
	 * this server can put them back: their ids belong to the old instance, the
	 * threads they are part of are not here, and minting new ones would either
	 * publish a decade of posts to the Fediverse again or fill the timeline
	 * with statuses no remote server can resolve. Mastodon's own import does
	 * not restore statuses either.
	 */
	public function testTheOutboxIsNotReplayedIntoTheTimeline(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile());
			$a->put('social/outbox.json', (string)json_encode([
				'type' => 'OrderedCollection',
				'totalItems' => 2,
				'orderedItems' => [['id' => 'https://cloud.example/1'], ['id' => 'https://cloud.example/2']],
			]));
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->streamRequest->expects($this->never())->method('save');

		$this->importing($archive);

		$this->assertStringContainsString('social/outbox.json', $this->output->text());
		$this->assertStringContainsString('not restored', $this->output->text());
	}

	public function testNothingAboutTheAccountIsFederatedAwayOnImport(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/actor.json', $this->actorFile(['movedTo' => 'https://old.example/users/alice']));
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		// a Move is the old server's to send, and `moved` on the new account
		// would point its own followers away from it
		$this->accountService->expects($this->never())->method('setMovedTo');
		$this->accountService->expects($this->never())->method('deleteActor');

		$this->importing($archive);
	}

	public function testAPartialArchiveIsNotAFailure(): void {
		$archive = $this->archiveOf(function (MigrationArchive $a): void {
			$a->put('social/following_accounts.csv', "Account address\ncarol@remote.example\n");
		});
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->migrationService->expects($this->once())->method('importFollows')
			->willReturn(['followed' => 1, 'skipped' => 0, 'failed' => []]);
		$this->accountService->expects($this->never())->method('setLocked');

		$this->importing($archive);

		$this->assertStringContainsString('social/actor.json', $this->output->text());
	}

	/**
	 * An archive whose owner never used this app still carries a version for
	 * this migrator, because the framework records one for every migrator that
	 * ran. Creating an account for that user here would hand somebody a
	 * Fediverse identity they never asked for, and publish it.
	 */
	public function testAnArchiveWithAVersionAndNoSocialFilesAtAllCreatesNoAccount(): void {
		$this->accountService->expects($this->never())->method('getActorFromUserId');
		$this->migrationService->expects($this->never())->method('importFollows');
		$this->actorRelationRequest->expects($this->never())->method('save');
		$this->streamActionService->expects($this->never())->method('setActionBool');

		$this->importing($this->archiveOf(static function (MigrationArchive $a): void {
		}));
	}

	/**
	 * The two halves against each other: what an export writes is what an
	 * import reads.
	 */
	public function testAnExportedArchiveImportsBackIntoTheSameFollowsAndBlocks(): void {
		$this->accountService->method('getActorFromUserId')->willReturn($this->alice());
		$this->followsRequest->method('getFollowingByActorId')
			->willReturnCallback(fn (string $actorId, int $limit, int $offset): array => $offset === 0
				? [$this->follow(self::CAROL, 'carol@remote.example')]
				: []);
		$this->followsRequest->method('getFollowersByActorId')->willReturn([]);
		$this->actorRelationRequest->method('getByActor')
			->willReturnCallback(fn (string $actorId, string $type): array => $type === ActorRelation::TYPE_BLOCK
				? [$this->relation(self::DAVE, ActorRelation::TYPE_BLOCK)]
				: []);
		$this->cacheActorService->method('getFromId')->willReturn($this->person(self::DAVE, 'dave@other.example'));
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::DAVE, 'dave@other.example'));
		$this->streamRequest->method('getTimeline')->willReturn([]);

		$archive = $this->export();
		$archive->setVersion('social', $this->migrator->getVersion());

		$followed = null;
		$this->migrationService->method('importFollows')
			->willReturnCallback(function (string $userId, string $csv) use (&$followed): array {
				$followed = MigrationService::parseFollowsCsv($csv);

				return ['followed' => count($followed), 'skipped' => 0, 'failed' => []];
			});
		$blocked = [];
		$this->actorRelationRequest->method('save')
			->willReturnCallback(function (string $actorId, string $objectId, string $type) use (&$blocked): void {
				$blocked[] = $objectId;
			});

		$this->importing($archive);

		$this->assertSame(['carol@remote.example'], $followed);
		$this->assertSame([self::DAVE], $blocked);
	}
}
