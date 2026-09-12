<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ReportsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Exceptions\ReportNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\AdminAccount;
use OCA\Social\Model\Moderation;
use OCA\Social\Model\Report;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AdminApiService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\ReportService;
use OCA\Social\Settings\AdminSection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What the admin API reads and decides, over the moderation that already
 * existed.
 *
 * The three reads go through `IDBConnection`, which this suite cannot even
 * mock — DBAL is not loadable in it — so the statement-building methods are
 * doubled and what is checked here is everything above them: which filters
 * become which question, which source a page is read from, and that every
 * decision is taken by the service that already took it.
 */
class AdminApiServiceTest extends TestCase {
	private const LOCAL = 'https://cloud.example/users/alice';
	private const REMOTE = 'https://remote.example/users/bob';
	private const PURGED = 'https://evil.example/users/carol';

	private IGroupManager|MockObject $groupManager;
	private AccountService|MockObject $accountService;
	private CacheActorService|MockObject $cacheActorService;
	private ConfigService|MockObject $configService;
	private FediverseService|MockObject $fediverseService;
	private ModerationService|MockObject $moderationService;
	private ReportService|MockObject $reportService;
	private ReportsRequest|MockObject $reportsRequest;
	private StreamRequest|MockObject $streamRequest;
	private IUserManager|MockObject $userManager;
	private ISettingsManager|MockObject $settingsManager;

	/** @var string[] the user ids the Social settings section is delegated to */
	private array $delegatedTo = [];
	/** @var string[] the user ids the server knows */
	private array $knownUsers = ['root', 'mod', 'alice'];
	/** Which settings section the delegation was asked about. */
	private string $askedAboutSection = '';

	/** @var array<string, string> actor id => the decision standing against it */
	private array $decisions = [];
	/** @var array<int, array<string, mixed>> what the account query answers */
	private array $accountRows = [];
	/** @var array<int, array<string, mixed>> what the report query answers */
	private array $reportRows = [];
	/** @var array<string, mixed>|null the arguments the account query was built from */
	private ?array $accountQuery = null;
	/** @var array<string, mixed>|null the arguments the report query was built from */
	private ?array $reportQuery = null;
	/** @var array<int, array> [method, id, user] of every write to a report row */
	private array $writes = [];
	/** @var string[] the addresses the access list holds */
	private array $accessList = [];
	private string $accessType = 'all_but';

	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->fediverseService = $this->createMock(FediverseService::class);
		$this->moderationService = $this->createMock(ModerationService::class);
		$this->reportService = $this->createMock(ReportService::class);
		$this->reportsRequest = $this->createMock(ReportsRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->settingsManager = $this->createMock(ISettingsManager::class);

		$this->userManager->method('get')->willReturnCallback(
			function (string $userId): ?IUser {
				if (!in_array($userId, $this->knownUsers, true)) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($userId);

				return $user;
			}
		);
		$this->settingsManager->method('getAllowedAdminSettings')->willReturnCallback(
			function (string $section, IUser $user): array {
				$this->askedAboutSection = $section;

				return in_array($user->getUID(), $this->delegatedTo, true) ? [50 => ['a setting']] : [];
			}
		);

		$this->moderationService->method('levelOf')
			->willReturnCallback(fn (string $actorId): string => $this->decisions[$actorId] ?? '');
		$this->moderationService->method('decisions')
			->willReturnCallback(function (): array {
				$decisions = [];
				foreach ($this->decisions as $actorId => $level) {
					$decisions[] = new Moderation($actorId, $level, '', 1757548800);
				}

				return $decisions;
			});

		$this->cacheActorService->method('getCachedFromIds')
			->willReturnCallback(function (array $ids): array {
				$actors = [];
				foreach ($ids as $id) {
					$actor = $this->known($id);
					if ($actor !== null) {
						$actors[$id] = $actor;
					}
				}

				return $actors;
			});

		$this->fediverseService->method('getAccessType')->willReturnCallback(fn (): string => $this->accessType);
		$this->fediverseService->method('getListedAddresses')->willReturnCallback(fn (): array => $this->accessList);
		$this->fediverseService->method('addAddress')
			->willReturnCallback(function (string $address): void {
				// the real one keeps each entry once (FediverseServiceTest
				// covers that); this stands in for it so a retry here is the
				// no-op it is in the app
				if (!in_array($address, $this->accessList, true)) {
					$this->accessList[] = $address;
				}
			});
		$this->fediverseService->method('removeAddress')
			->willReturnCallback(function (string $address): void {
				$this->accessList = array_values(array_diff($this->accessList, [$address]));
			});
		$this->fediverseService->method('isLocal')
			->willReturnCallback(static fn (string $host): bool => $host === 'cloud.example');
	}

	/** The accounts this instance has a cached actor for. */
	private function known(string $id): ?Person {
		$byId = [
			self::LOCAL => [7, true, ''],
			self::REMOTE => [9, false, 'bob@remote.example'],
		];

		if (!isset($byId[$id])) {
			return null;
		}

		[$nid, $local, $account] = $byId[$id];

		$person = new Person();
		$person->setPreferredUsername($local ? 'alice' : 'bob')
			->setAccount($account)
			->setCreation(1757548800);
		$person->setId($id);
		$person->setNid($nid);
		$person->setLocal($local);

		return $person;
	}

	private function service(): AdminApiService|MockObject {
		$service = $this->getMockBuilder(AdminApiService::class)
			->disableOriginalConstructor()
			->onlyMethods(['accountRows', 'reportRows', 'setAssignment', 'setActionTaken'])
			->getMock();

		$service->method('accountRows')->willReturnCallback(
			function (
				?bool $local, string $username, string $displayName, string $domain,
				bool $undecided, int $limit, int $maxId, int $minId,
			): array {
				$this->accountQuery = compact(
					'local', 'username', 'displayName', 'domain', 'undecided', 'limit', 'maxId', 'minId'
				);

				return $this->accountRows;
			}
		);
		$service->method('reportRows')->willReturnCallback(
			function (
				?bool $resolved, string $reporter, string $target,
				int $limit, int $maxId, int $minId, int $id = 0,
			): array {
				$this->reportQuery = compact('resolved', 'reporter', 'target', 'limit', 'maxId', 'minId', 'id');

				return ($id > 0)
					? array_values(array_filter(
						$this->reportRows, static fn (array $row): bool => (int)$row['id'] === $id
					))
					: $this->reportRows;
			}
		);
		$service->method('setAssignment')->willReturnCallback(function (int $id, ?string $userId): void {
			$this->writes[] = ['assign', $id, $userId];
		});
		$service->method('setActionTaken')->willReturnCallback(function (int $id, ?string $userId): void {
			$this->writes[] = ['action', $id, $userId];
		});

		foreach ([
			'groupManager' => $this->groupManager,
			'accountService' => $this->accountService,
			'cacheActorService' => $this->cacheActorService,
			'configService' => $this->configService,
			'fediverseService' => $this->fediverseService,
			'moderationService' => $this->moderationService,
			'reportService' => $this->reportService,
			'reportsRequest' => $this->reportsRequest,
			'streamRequest' => $this->streamRequest,
			'userManager' => $this->userManager,
			'settingsManager' => $this->settingsManager,
			'logger' => new NullLogger(),
		] as $name => $dependency) {
			// the constructor is not run (it takes an IDBConnection, which
			// cannot be mocked here), so the dependencies go in directly
			(new \ReflectionProperty(AdminApiService::class, $name))->setValue($service, $dependency);
		}

		return $service;
	}

	private function accountRow(string $id, int $nid, bool $local, ?string $level = null): array {
		return [
			'id' => $id,
			'nid' => $nid,
			'local' => $local ? 1 : 0,
			'account' => $local ? '' : 'bob@remote.example',
			'preferred_username' => $local ? 'alice' : 'bob',
			'level' => $level,
		];
	}

	private function reportRow(array $overrides = []): array {
		return array_merge([
			'id' => 4,
			'actor_id' => self::REMOTE,
			'account_id' => self::LOCAL,
			'status_ids' => '[]',
			'comment' => 'spam',
			'category' => 'spam',
			'local' => 1,
			'resolved' => 0,
			'creation' => '2025-09-11 00:00:00',
			'assigned_to' => null,
			'action_taken_by' => null,
			'action_taken_at' => null,
		], $overrides);
	}

	/**
	 * Moderating used to mean administering the whole server, which is a great
	 * deal of power to hand somebody so that they can act on a report. Whoever
	 * the administrator has handed the Social settings section to may now
	 * moderate, and Nextcloud's own delegation is the only list of them.
	 */
	public function testWhoeverMayOpenTheSettingsSectionMayModerate(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->delegatedTo = ['mod'];

		$service = $this->service();

		$this->assertTrue($service->isAdministrator('mod'));
		$this->assertFalse($service->isAdministrator('alice'));
	}

	/** It is asked about the Social section, not about admin settings at large. */
	public function testTheDelegationIsAskedAboutThisSection(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->delegatedTo = ['mod'];

		$this->service()->isAdministrator('mod');

		$this->assertSame(AdminSection::SECTION_ID, $this->askedAboutSection);
	}

	/** The default is the behaviour this app had: admins and nobody else. */
	public function testWithNothingDelegatedNobodyGainsAnything(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->assertFalse($this->service()->isAdministrator('alice'));
	}

	/** A user id that names nobody is not a moderator by default. */
	public function testAUserIdThatNamesNobodyIsRefused(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->delegatedTo = ['ghost'];
		$this->knownUsers = [];

		$this->assertFalse($this->service()->isAdministrator('ghost'));
	}

	public function testANextcloudAdministratorAlwaysCounts(): void {
		$this->groupManager->method('isAdmin')
			->willReturnCallback(static fn (string $userId): bool => $userId === 'root');

		$service = $this->service();
		$this->assertTrue($service->isAdministrator('root'));
		$this->assertFalse($service->isAdministrator('alice'));
		// nobody behind the request is not a moderator either, and neither the
		// group manager nor the delegation is asked about an empty user id
		$this->assertFalse($service->isAdministrator(''));
	}

	public function testThePageCarriesTheDecisionStandingAgainstEachAccount(): void {
		$this->accountRows = [
			$this->accountRow(self::REMOTE, 9, false, Moderation::SILENCE),
			$this->accountRow(self::LOCAL, 7, true),
		];

		$page = $this->service()->accountPage();

		$this->assertSame(['9', '7'], array_map(
			static fn (AdminAccount $account): string => $account->getId(), $page['accounts']
		));
		$this->assertTrue($page['accounts'][0]->isSilenced());
		$this->assertFalse($page['accounts'][1]->isSilenced());
		$this->assertSame([9, 7], $page['cursors']);
	}

	public function testARowWhoseAccountCannotBeReadStillMovesTheCursor(): void {
		$this->accountRows = [
			$this->accountRow(self::LOCAL, 7, true),
			$this->accountRow('https://gone.example/users/dan', 5, false),
		];

		$page = $this->service()->accountPage();

		$this->assertCount(1, $page['accounts']);
		// without the missing row's nid the next page would start above it and
		// hand the client the same page for ever
		$this->assertSame([7, 5], $page['cursors']);
	}

	public function testTheFiltersBecomeThePredicatesOfThePage(): void {
		$this->service()->accountPage(false, 'BOB', 'Bobby', 'remote.example', 'active', 500, 12, 3);

		$this->assertSame([
			'local' => false,
			'username' => 'BOB',
			'displayName' => 'Bobby',
			'domain' => 'remote.example',
			'undecided' => true,
			// a client may not ask for a page larger than the instance will build
			'limit' => AdminApiService::MAX_LIMIT,
			'maxId' => 12,
			'minId' => 3,
		], $this->accountQuery);
	}

	public function testAStateThisAppHasNotIsAnEmptyPageAndNotEveryAccount(): void {
		$this->accountRows = [$this->accountRow(self::LOCAL, 7, true)];

		foreach (['pending', 'disabled'] as $status) {
			$page = $this->service()->accountPage(null, '', '', '', $status);

			$this->assertSame([], $page['accounts'], $status . ' must not list every account');
			$this->assertSame([], $page['cursors']);
		}

		// and the query was never built at all
		$this->assertNull($this->accountQuery);
	}

	public function testASuspendedAccountIsListedFromTheDecisionThatSuspendedIt(): void {
		// the suspension purged the cached actor: reading the account table
		// would show a moderator nothing at all under "suspended"
		$this->decisions = [self::PURGED => Moderation::SUSPEND, self::REMOTE => Moderation::SILENCE];

		$page = $this->service()->accountPage(null, '', '', '', 'suspended');

		$this->assertCount(1, $page['accounts']);
		$this->assertSame(self::PURGED, $page['accounts'][0]->getId());
		$this->assertTrue($page['accounts'][0]->isSuspended());
		$this->assertSame('carol', $page['accounts'][0]->getUsername());
		// nothing pages the decisions, so no cursor is offered for them
		$this->assertSame([], $page['cursors']);
		$this->assertNull($this->accountQuery);
	}

	public function testASilencedAccountStillCachedIsListedWithItsRealProfile(): void {
		$this->decisions = [self::REMOTE => Moderation::SILENCE];

		$page = $this->service()->accountPage(null, '', '', '', 'silenced');

		$this->assertCount(1, $page['accounts']);
		$this->assertSame('9', $page['accounts'][0]->getId());
		$this->assertSame('remote.example', $page['accounts'][0]->getDomain());
	}

	public function testTheDecisionsPageIsFilteredLikeAnyOther(): void {
		$this->decisions = [self::PURGED => Moderation::SUSPEND, self::REMOTE => Moderation::SUSPEND];

		$service = $this->service();

		$this->assertCount(2, $service->accountPage(false, '', '', '', 'suspended')['accounts']);
		$this->assertCount(0, $service->accountPage(true, '', '', '', 'suspended')['accounts']);
		$this->assertCount(1, $service->accountPage(null, 'carol', '', '', 'suspended')['accounts']);
		$this->assertCount(1, $service->accountPage(null, '', '', 'evil.example', 'suspended')['accounts']);
		$this->assertCount(1, $service->accountPage(null, '', '', '', 'suspended', 1)['accounts']);
	}

	public function testAnAccountIsFoundByEveryFormAnIdComesIn(): void {
		$this->decisions = [self::REMOTE => Moderation::SILENCE];
		$this->cacheActorService->method('getFromNids')
			->willReturnCallback(fn (array $nids): array => ($nids === [9]) ? [$this->known(self::REMOTE)] : []);
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(fn (string $account): Person => $this->known(self::REMOTE));

		$service = $this->service();

		$this->assertSame('9', $service->account('9')->getId());
		$this->assertSame('9', $service->account(self::REMOTE)->getId());
		$this->assertSame('9', $service->account('@bob@remote.example')->getId());
		$this->assertTrue($service->account('9')->isSilenced());
	}

	public function testASuspendedAccountIsStillAddressableByItsActorId(): void {
		$this->decisions = [self::PURGED => Moderation::SUSPEND];

		// its cached actor is gone, so a numeric id no longer exists for it —
		// this is what makes the suspension liftable over the API
		$account = $this->service()->account(self::PURGED);

		$this->assertSame(self::PURGED, $account->getId());
		$this->assertTrue($account->isSuspended());
	}

	public function testAnAccountThisInstanceDoesNotHoldIsNotFound(): void {
		$this->cacheActorService->method('getFromNids')->willReturn([]);
		$this->cacheActorService->method('getFromAccount')
			->willThrowException(new ItemNotFoundException('nope'));

		$service = $this->service();

		foreach (['404', 'https://nowhere.example/users/nobody', 'nobody@nowhere.example', ''] as $reference) {
			try {
				$service->account($reference);
				$this->fail('"' . $reference . '" must not resolve to an account');
			} catch (ItemNotFoundException $e) {
				$this->assertSame('Record not found', $e->getMessage());
			}
		}
	}

	public function testADecisionIsTakenByTheServiceThatAlreadyTookIt(): void {
		$account = AdminAccount::fromPerson($this->known(self::REMOTE));

		$this->moderationService->expects($this->once())
			->method('decide')
			->with(self::REMOTE, Moderation::SUSPEND, 'spamming');

		$this->assertTrue($this->service()->act($account, 'suspend', 'spamming')->isSuspended());
	}

	public function testNoneLiftsWhateverStands(): void {
		$account = AdminAccount::fromPerson($this->known(self::REMOTE), Moderation::SILENCE);

		$this->moderationService->expects($this->once())->method('lift')->with(self::REMOTE);
		$this->moderationService->expects($this->never())->method('decide');

		$this->assertFalse($this->service()->act($account, 'none')->isSilenced());
	}

	public function testAnActionThisAppHasNoStateForIsRefused(): void {
		$account = AdminAccount::fromPerson($this->known(self::REMOTE));

		$this->moderationService->expects($this->never())->method('decide');
		$this->moderationService->expects($this->never())->method('lift');

		foreach (['sensitive', 'disable', 'banish'] as $type) {
			try {
				$this->service()->act($account, $type);
				$this->fail('"' . $type . '" must not be applied');
			} catch (\InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}

	public function testEachLiftTouchesOnlyItsOwnDecision(): void {
		$suspended = AdminAccount::fromPerson($this->known(self::REMOTE), Moderation::SUSPEND);
		$silenced = AdminAccount::fromPerson($this->known(self::REMOTE), Moderation::SILENCE);

		// unsilencing a suspended account must not set it free
		$this->moderationService->expects($this->exactly(2))->method('lift');

		$this->assertTrue($this->service()->unsilence($suspended)->isSuspended());
		$this->assertTrue($this->service()->unsuspend($silenced)->isSilenced());
		$this->assertFalse($this->service()->unsuspend($suspended)->isSuspended());
		$this->assertFalse($this->service()->unsilence($silenced)->isSilenced());
	}

	public function testAReportNamesBothAccountsAndItsPosts(): void {
		$this->reportRows = [$this->reportRow(['status_ids' => '["https://remote.example/note/1"]'])];

		$note = new Note();
		$note->setNid(11);
		$this->streamRequest->method('getStreamById')->willReturn($note);

		$report = $this->service()->report(4);
		$entity = $report->jsonSerialize();

		$this->assertSame('4', $entity['id']);
		$this->assertSame('9', $entity['account']->getId());
		$this->assertSame('7', $entity['target_account']->getId());
		$this->assertCount(1, $entity['statuses']);
		$this->assertNull($entity['assigned_account']);
	}

	public function testAReportAboutAnAccountThatIsGoneStillNamesIt(): void {
		// a suspension purges the account and keeps the report
		$this->reportRows = [$this->reportRow(['account_id' => self::PURGED])];
		$this->decisions = [self::PURGED => Moderation::SUSPEND];

		$entity = $this->service()->report(4)->jsonSerialize();

		$this->assertSame(self::PURGED, $entity['target_account']->getId());
		$this->assertTrue($entity['target_account']->isSuspended());
	}

	public function testAReportedPostThatIsGoneIsLeftOutRatherThanHalfSent(): void {
		$this->reportRows = [$this->reportRow(['status_ids' => '["https://remote.example/note/1"]'])];
		$this->streamRequest->method('getStreamById')
			->willThrowException(new \OCA\Social\Exceptions\StreamNotFoundException());

		$this->assertSame([], $this->service()->report(4)->jsonSerialize()['statuses']);
	}

	public function testTheMissingReportIsARefusalAndNotAnEmptyEntity(): void {
		$this->expectException(ReportNotFoundException::class);

		$this->service()->report(404);
	}

	public function testTheResolvedFilterIsThreeStateAndNotTwo(): void {
		$this->reportRows = [$this->reportRow()];

		// "every report" and "the open queue" are different questions, and a
		// filter that could only say true or false could not ask the first
		$this->service()->reports(null);
		$this->assertNull($this->reportQuery['resolved']);

		$this->service()->reports(false);
		$this->assertFalse($this->reportQuery['resolved']);

		$this->service()->reports(true);
		$this->assertTrue($this->reportQuery['resolved']);
	}

	public function testAFilterOnAnUnknownAccountMatchesNothingRatherThanEverything(): void {
		$this->reportRows = [$this->reportRow()];
		$this->cacheActorService->method('getFromNids')->willReturn([]);
		$this->cacheActorService->method('getFromAccount')
			->willThrowException(new ItemNotFoundException('nope'));

		$this->service()->reports(null, '404');

		$this->assertNotSame('', $this->reportQuery['reporter']);
		$this->assertStringNotContainsString('404', $this->reportQuery['reporter']);
	}

	public function testResolvingAReportRecordsWhoResolvedIt(): void {
		$this->reportRows = [$this->reportRow(['resolved' => 1])];

		$this->reportService->expects($this->once())->method('setResolved')->with(4, true);

		$entity = $this->service()->resolveReport(4, 'root')->jsonSerialize();

		$this->assertSame([['action', 4, 'root']], $this->writes);
		$this->assertTrue($entity['action_taken']);
	}

	public function testReopeningAReportClearsTheDecisionItDescribed(): void {
		$this->reportRows = [$this->reportRow()];

		$this->reportService->expects($this->once())->method('setResolved')->with(4, false);

		$this->service()->reopenReport(4);

		$this->assertSame([['action', 4, null]], $this->writes);
	}

	public function testAssigningAReportGoesThroughTheReportItNames(): void {
		$this->reportRows = [$this->reportRow()];

		// the report is read first, so assigning one that is not there is a
		// refusal rather than a write against nothing
		$this->reportsRequest->expects($this->exactly(2))->method('getById')->with(4);

		$this->service()->assignReport(4, 'root');
		$this->service()->assignReport(4, null);

		$this->assertSame([['assign', 4, 'root'], ['assign', 4, null]], $this->writes);
	}

	public function testAssigningAReportThatIsNotThereWritesNothing(): void {
		$this->reportsRequest->method('getById')
			->willThrowException(new ReportNotFoundException('report 404 not found'));

		$this->expectException(ReportNotFoundException::class);

		try {
			$this->service()->assignReport(404, 'root');
		} finally {
			$this->assertSame([], $this->writes);
		}
	}

	public function testAnAssignedReportNamesTheModeratorHandlingIt(): void {
		$this->reportRows = [$this->reportRow(['assigned_to' => 'root', 'action_taken_by' => 'root'])];
		$this->accountService->method('getActorFromUserId')->willReturn($this->known(self::LOCAL));

		$entity = $this->service()->report(4)->jsonSerialize();

		$this->assertSame('7', $entity['assigned_account']->getId());
		$this->assertSame('7', $entity['action_taken_by_account']->getId());
	}

	public function testAModeratorWithoutASocialAccountIsNobodyRatherThanAFailure(): void {
		$this->reportRows = [$this->reportRow(['assigned_to' => 'root'])];
		$this->accountService->method('getActorFromUserId')
			->willThrowException(new ItemNotFoundException('no actor'));

		$this->assertNull($this->service()->report(4)->jsonSerialize()['assigned_account']);
	}

	public function testTheAccessListIsServedAsDomainBlocks(): void {
		$this->accessList = ['Evil.example', ' ', 'other.example'];

		$blocks = $this->service()->domainBlocks();

		$this->assertSame(
			['evil.example', 'other.example'],
			array_map(static fn ($block): string => $block->getDomain(), $blocks)
		);
	}

	public function testABlockIsFoundByItsIdAndByItsDomain(): void {
		$this->accessList = ['evil.example'];

		$service = $this->service();
		$id = $service->domainBlocks()[0]->getId();

		$this->assertSame('evil.example', $service->domainBlock($id)->getDomain());
		$this->assertSame('evil.example', $service->domainBlock('evil.example')->getDomain());

		$this->expectException(ItemNotFoundException::class);
		$service->domainBlock('never-blocked.example');
	}

	public function testBlockingADomainAddsItOnceAndOnlyAsAHostname(): void {
		$service = $this->service();

		$service->blockDomain('Evil.example');
		$service->blockDomain('evil.example');

		$this->assertSame(['evil.example'], $this->accessList);

		$this->expectException(\InvalidArgumentException::class);
		$service->blockDomain('https://evil.example/path');
	}

	public function testTheOnlySeverityThisListCanExpressIsSuspend(): void {
		$service = $this->service();

		$service->assertSeverity('');
		$service->assertSeverity('suspend');

		// a client told its silence had been applied would believe the domain
		// was under a lesser block than it is
		$this->expectException(\InvalidArgumentException::class);
		$service->assertSeverity('silence');
	}

	public function testUnblockingLiftsTheEntryItNames(): void {
		$this->accessList = ['evil.example', 'other.example'];

		$this->assertSame('evil.example', $this->service()->unblockDomain('evil.example')->getDomain());
		$this->assertSame(['other.example'], $this->accessList);
	}

	public function testAnAllowListInstanceRefusesEveryDomainBlockRoute(): void {
		// the same app value holds both lists; served as blocks its entries
		// would read as their own opposite, and "blocking" one would have
		// allowed it
		$this->accessType = 'none_but';
		$this->accessList = ['friend.example'];
		$this->configService->accessTypeList = ['BLACKLIST' => 'all_but', 'WHITELIST' => 'none_but'];

		$service = $this->service();

		foreach ([
			fn () => $service->domainBlocks(),
			fn () => $service->domainBlock('friend.example'),
			fn () => $service->blockDomain('evil.example'),
			fn () => $service->unblockDomain('friend.example'),
		] as $call) {
			try {
				$call();
				$this->fail('a domain-block route answered on an allow-list instance');
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString('allow list', $e->getMessage());
			}
		}

		$this->assertSame(['friend.example'], $this->accessList);
	}
}
