<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Model\RepoHead;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Move\MoveAnnouncement;
use OCA\Social\Atproto\Move\MoveAwayService;
use OCA\Social\Atproto\Move\MoveFailure;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Cron\AtprotoMove;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoMoveRequest;
use OCA\Social\Db\AtprotoOAuthRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoKeysSealedException;
use OCA\Social\Exceptions\MoveErrorException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class MoveAwayServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const PDS = 'https://pds.example.com';
	private const PDS_DID = 'did:web:pds.example.com';

	/** @var list<array{method: string, verb: string, query: array, json: ?array, bytes: mixed, token: string}> */
	private array $calls = [];
	/** @var array<string, list<array{status: int, body: array}>> what the other PDS answers, by method, in turn */
	private array $answers = [];
	/** @var array<int, Move> */
	private array $rows = [];
	private ?RepoHead $head = null;
	/** @var Publisher&MockObject */
	private Publisher $publisher;
	/** @var MoveAnnouncement&MockObject */
	private MoveAnnouncement $announcement;
	/** @var (callable(string): void)|null told of every call to the other PDS before it is answered */
	private $pdsHook = null;
	private array $queued = [];
	private PrivateKey $key;
	private Identity $alice;
	/** @var IdentityService&MockObject */
	private IdentityService $identities;
	private MoveAwayService $moves;

	protected function setUp(): void {
		$this->key = PrivateKey::generate(Curve::K256);
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', 'did:key:zRecovery', Identity::STATE_ACTIVE, '', 0);
		$pds = $this->createMock(PdsClient::class);
		$pds->method('origin')->willReturnCallback(static fn (string $typed): string => 'https://' . preg_replace('#^https?://#', '', rtrim($typed, '/')));
		$pds->method('call')->willReturnCallback(function (string $origin, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
			$this->calls[] = ['method' => $method, 'verb' => $verb, 'query' => $query, 'json' => $json, 'bytes' => $bytes, 'token' => $token];
			if ($this->pdsHook !== null) {
				($this->pdsHook)($method);
			}

			return $this->answer($method);
		});
		$pds->method('expect')->willReturnCallback(function (string $origin, string $method, string $verb = 'GET', array $query = [], ?array $json = null, $bytes = null, string $type = '', string $token = ''): array {
			$this->calls[] = ['method' => $method, 'verb' => $verb, 'query' => $query, 'json' => $json, 'bytes' => $bytes, 'token' => $token];
			$answer = $this->answer($method);
			if ($answer['status'] !== 200) {
				throw new AtprotoException($method . ' answered ' . $answer['status']);
			}

			return $answer['body'];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$this->identities = $this->createMock(IdentityService::class);
		$this->identities->method('signingKey')->willReturnCallback(fn (): PrivateKey => $this->key);
		$this->identities->method('getByDid')->willReturnCallback(fn (): Identity => $this->alice);
		$repositories = $this->createMock(RepositoryService::class);
		$repositories->method('exportCar')->with(self::DID)->willReturnCallback(fn (): string => 'the car at ' . ($this->head?->commitCid ?? 'nothing'));
		$repositories->method('getHead')->willReturnCallback(fn (): ?RepoHead => $this->head);
		$repositories->method('getRecord')->willReturnCallback(static function (string $did, string $collection, string $rkey): ?StoredRecord {
			if ($collection !== 'app.bsky.feed.post' || $rkey !== '3kpic') {
				return null;
			}
			$bytes = DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'my cat', 'createdAt' => '2026-03-01T10:00:00.000Z']);

			return new StoredRecord($did, $collection, $rkey, Cid::forDagCbor($bytes), $bytes, 'https://social.test/@alice/42', 1);
		});
		$cid = Cid::forRaw('pixels');
		$blobs = $this->createMock(AtprotoBlobRequest::class);
		$blobs->method('get')->willReturnCallback(static fn (string $did, string $c): ?BlobRef => $c === $cid->toString() ? new BlobRef(self::DID, $cid, 'doc', 'image/jpeg', 6) : null);
		$pictures = $this->createMock(PictureService::class);
		$pictures->method('read')->willReturn('pixels');
		$preferences = $this->createMock(Preferences::class);
		$preferences->method('get')->willReturn(['preferences' => [['$type' => 'app.bsky.actor.defs#adultContentPref', 'enabled' => false]]]);
		$request = $this->createMock(AtprotoMoveRequest::class);
		$request->method('add')->willReturnCallback(function (Move $move): int {
			$id = count($this->rows) + 1;
			$this->rows[$id] = new Move($id, $move->did, $move->userId, $move->direction, $move->pds, $move->pdsDid, $move->handle, $move->step, $move->state, $move->session, $move->progress);

			return $id;
		});
		$request->method('update')->willReturnCallback(function (Move $move): void {
			$this->rows[$move->id] = clone $move;
		});
		$request->method('get')->willReturnCallback(fn (int $id): ?Move => isset($this->rows[$id]) ? clone $this->rows[$id] : null);
		$request->method('latestOfUser')->willReturnCallback(fn (): ?Move => $this->rows === [] ? null : clone end($this->rows));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'sealed:' . base64_encode($plain));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $sealed): string => base64_decode(substr($sealed, 7)));
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $job, mixed $argument): void {
			$this->queued[] = [$job, $argument];
		});
		$this->publisher = $this->createMock(Publisher::class);
		$this->announcement = $this->createMock(MoveAnnouncement::class);
		$this->moves = new MoveAwayService(
			$pds, new ServiceAuth($time, $this->createStub(AtprotoOAuthRequest::class)), $this->identities, $repositories, $blobs, $pictures, $this->createMock(VideoBlobService::class),
			$preferences, $request, $crypto, $jobs, $this->publisher, $this->announcement, new NullLogger(),
		);
		$this->answers['com.atproto.server.describeServer'] = [['status' => 200, 'body' => ['did' => self::PDS_DID, 'availableUserDomains' => ['.pds.example.com'], 'inviteCodeRequired' => false]]];
		$this->answers['com.atproto.server.createAccount'] = [['status' => 200, 'body' => ['did' => self::DID, 'handle' => 'alice.pds.example.com', 'accessJwt' => 'access-1', 'refreshJwt' => 'refresh-1']]];
	}

	/** The other PDS's next answer to a method: what the test queued, else an empty 200. */
	private function answer(string $method): array {
		$this->answers[$method] ??= [];

		return array_shift($this->answers[$method]) ?? ['status' => 200, 'body' => []];
	}

	private function call(string $method): array {
		foreach ($this->calls as $call) {
			if ($call['method'] === $method) {
				return $call;
			}
		}
		$this->fail($method . ' was not called');
	}

	public function testTheAccountIsMadeThereWithATokenTheAccountSigned(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', '@alice.pds.example.com', 'alice@example.org', 'secret');

		$create = $this->call('com.atproto.server.createAccount');
		$this->assertSame(['did' => self::DID, 'handle' => 'alice.pds.example.com', 'email' => 'alice@example.org', 'password' => 'secret'], $create['json']);
		[$header, $payload, $signature] = explode('.', $create['token']);
		$claims = json_decode(Encoding::base64UrlDecode($payload), true);
		$this->assertSame(['iss' => self::DID, 'aud' => self::PDS_DID, 'lxm' => 'com.atproto.server.createAccount'], array_intersect_key($claims, ['iss' => 1, 'aud' => 1, 'lxm' => 1]));
		$this->assertTrue($this->key->publicKey()->verify($header . '.' . $payload, Encoding::base64UrlDecode($signature)));

		$this->assertSame(self::PDS, $move->pds);
		$this->assertSame(Move::STEP_REPO, $move->step);
		$this->assertStringNotContainsString('access-1', $this->rows[1]->session, 'the other server\'s session is sealed');
		$this->assertSame([[AtprotoMove::class, ['move' => 1]]], $this->queued);
	}

	public function testWhatTheOtherServerWillNotTakeIsSaidAtOnceWithACode(): void {
		$this->assertRefused('handle-not-allowed', 'gives out handles ending in .pds.example.com', 'alice.elsewhere.org');

		$this->answers['com.atproto.server.describeServer'] = [['status' => 200, 'body' => ['did' => self::PDS_DID, 'inviteCodeRequired' => true]]];
		$this->assertRefused('invite-code-required', 'invite code');

		$this->answers['com.atproto.server.describeServer'] = [['status' => 200, 'body' => []]];
		$this->assertRefused('pds-unknown', 'does not say what it is');

		foreach (['HandleNotAvailable' => 'handle-taken', 'InvalidInviteCode' => 'invite-code-invalid', 'SomethingNew' => 'account-not-created'] as $error => $code) {
			$this->answers['com.atproto.server.describeServer'] = [['status' => 200, 'body' => ['did' => self::PDS_DID]]];
			$this->answers['com.atproto.server.createAccount'] = [['status' => 400, 'body' => ['error' => $error, 'message' => 'Not like this']]];
			$this->assertRefused($code, 'Not like this');
		}
	}

	public function testAServerThatCannotBeReachedHasItsOwnCode(): void {
		$this->answers['com.atproto.server.describeServer'] = [['status' => 500, 'body' => []]];
		$this->assertRefused('pds-refused', 'describeServer answered 500');

		$this->assertSame('pds-unreachable', MoveAwayService::codeOf(new AtprotoException('The PDS at https://x could not be reached: timeout')));
		$this->assertSame('pds-not-public', MoveAwayService::codeOf(new AtprotoException('The PDS at https://x could not be reached: host does not resolve to a public address: x')));
		$this->assertSame('pds-address-invalid', MoveAwayService::codeOf(new AtprotoException('That is not the https address of a PDS')));
		$this->assertSame('keys-sealed', MoveAwayService::codeOf(new AtprotoKeysSealedException('signing key of did:plc:x')));
		$this->assertSame('plc-refused', MoveAwayService::codeOf(new \OCA\Social\Exceptions\AtprotoPlcRefusedException('PLC directory refused the operation (400): bad')));
		$this->assertSame('plc-unreachable', MoveAwayService::codeOf(new AtprotoException('PLC directory could not be reached: timeout')));
		$this->assertSame('pds-blocked', MoveAwayService::codeOf(new MoveFailure('pds-blocked', 'The PDS at https://x could not be reached: blocked')), 'a code PdsClient gives is kept');
		$this->assertSame('rate-limited', MoveAwayService::codeOf(PdsClient::refused('com.atproto.repo.importRepo', ['status' => 429, 'body' => []])));
	}

	public function testAFailedStepKeepsItsCode(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->answers['com.atproto.repo.importRepo'] = [['status' => 413, 'body' => ['error' => 'PayloadTooLarge']]];

		$this->moves->run($move->id);

		$this->assertSame(Move::FAILED, $this->rows[1]->state);
		$this->assertSame('pds-refused', $this->rows[1]->progress['code']);
		$this->moves->retry('alice');
		$this->assertArrayNotHasKey('code', $this->rows[1]->progress, 'a move started again has no error');
	}

	public function testWithoutARecoveryKeyThePersonSaysSoFirst(): void {
		$this->alice = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->assertRefused('recovery-key-missing', 'only the other server controls the account');
		$this->assertNotContains('com.atproto.server.createAccount', array_column($this->calls, 'method'), 'nothing was asked of the other server');

		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'a@example.org', 'pw', '', true);
		$this->assertSame(Move::RUNNING, $move->state);
	}

	public function testThePageIsToldWhoControlsTheAccountAfterwards(): void {
		$this->assertSame(['has_recovery_key' => true, 'rotation_keys' => ['recovery', 'new_server'], 'new_host' => null], MoveAwayService::control($this->alice, null));
		$bare = new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->assertSame(['new_server'], MoveAwayService::control($bare, null)['rotation_keys']);

		$done = new Move(1, self::DID, 'alice', Move::AWAY, 'https://pds.example.com:8443', self::PDS_DID, 'alice.pds.example.com', Move::STEP_DONE, Move::DONE);
		$this->assertSame('pds.example.com', MoveAwayService::control($this->alice, $done)['new_host']);
		$done->state = Move::RUNNING;
		$this->assertNull(MoveAwayService::control($this->alice, $done)['new_host'], 'the host only once the move is done');
		$this->assertStringNotContainsString('did:key', (string)json_encode(MoveAwayService::control($this->alice, $done)), 'roles, never keys');
	}

	public function testTheTargetSaysWhichHandlesItGivesOut(): void {
		$this->answers['com.atproto.server.describeServer'] = [['status' => 200, 'body' => ['did' => self::PDS_DID, 'availableUserDomains' => ['.pds.example.com', 42, ''], 'inviteCodeRequired' => true, 'links' => []]]];

		$this->assertSame(
			['pds' => self::PDS, 'did' => self::PDS_DID, 'availableUserDomains' => ['.pds.example.com'], 'inviteCodeRequired' => true],
			$this->moves->target('pds.example.com/'),
		);
		$this->assertNotContains('com.atproto.server.createAccount', array_column($this->calls, 'method'));

		$this->answers['com.atproto.server.describeServer'] = [['status' => 200, 'body' => ['availableUserDomains' => []]]];
		try {
			$this->moves->target('pds.example.com');
			$this->fail('a server without a DID');
		} catch (MoveErrorException $e) {
			$this->assertSame('pds-unknown', $e->errorCode);
		}
	}

	public function testEachStepSaysWhereTheAccountStands(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->assertSame('account_created', $this->rows[1]->progress['stage']);
		$this->answers['com.atproto.server.activateAccount'] = [['status' => 500, 'body' => ['error' => 'InternalServerError']]];

		$this->moves->run($move->id);

		$this->assertSame([Move::FAILED, Move::STEP_ACTIVATE, 'identity_switched'], [$this->rows[1]->state, $this->rows[1]->step, $this->rows[1]->progress['stage']]);

		$this->calls = [];
		$this->moves->retry('alice');
		$this->moves->run($move->id);
		$this->assertSame(['com.atproto.server.activateAccount'], array_column($this->calls, 'method'), 'Try again goes on from the stage it stopped at');
		$this->assertSame('done', $this->rows[1]->progress['stage']);
	}

	public function testAMoveIsCalledOffBeforeTheSwitchAndTheAccountThereIsLeftForThePerson(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->answers['com.atproto.repo.listMissingBlobs'] = [['status' => 502, 'body' => []]];
		$this->moves->run($move->id);

		$called = $this->moves->cancel('alice');

		$this->assertSame([Move::CANCELLED, 'target-account-left', 'repo_copied', ''], [$this->rows[1]->state, $this->rows[1]->progress['code'], $this->rows[1]->progress['stage'], $this->rows[1]->session]);
		$this->assertSame(Move::CANCELLED, $called->state);
		$this->identities->expects($this->never())->method('handOver');
		$this->moves->run($move->id);
		$this->assertFalse($called->isUnderWay(), 'a new move may start');
	}

	public function testARunningMoveCalledOffStopsBeforeTheDidMoves(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->answers['app.bsky.actor.putPreferences'] = [['status' => 200, 'body' => []]];
		$this->identities->expects($this->never())->method('handOver');
		$this->answers['com.atproto.identity.getRecommendedDidCredentials'] = [['status' => 200, 'body' => []]];
		// the person calls it off while the preferences are copied
		$this->pdsHook = function (string $method): void {
			if ($method === 'app.bsky.actor.putPreferences') {
				$this->moves->cancel('alice');
			}
		};

		$this->moves->run($move->id);

		$this->assertSame(Move::CANCELLED, $this->rows[1]->state);
	}

	public function testAfterTheSwitchAMoveIsNotCalledOff(): void {
		foreach ([[Move::STEP_ACTIVATE, Move::FAILED, 'move-past-switch'], [Move::STEP_IDENTITY, Move::RUNNING, 'move-switching'], [Move::STEP_DONE, Move::DONE, 'no-move-to-call-off']] as [$step, $state, $code]) {
			$this->rows = [1 => new Move(1, self::DID, 'alice', Move::AWAY, self::PDS, self::PDS_DID, 'alice.pds.example.com', $step, $state)];
			try {
				$this->moves->cancel('alice');
				$this->fail('called off at ' . $step);
			} catch (MoveErrorException $e) {
				$this->assertSame($code, $e->errorCode);
			}
		}
	}

	public function testMissingPicturesStopTheMoveBeforeTheDidMoves(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->head = new RepoHead(self::DID, 'bafyhead', 'rev1', 12, 0, 0);
		$this->answers['com.atproto.server.checkAccountStatus'] = [['status' => 200, 'body' => ['repoCommit' => 'bafyhead', 'indexedRecords' => 12, 'expectedBlobs' => 5, 'importedBlobs' => 2]]];
		$this->identities->expects($this->never())->method('handOver');

		$this->answers['com.atproto.repo.listMissingBlobs'] = [
			// the copy finds nothing more it can send
			['status' => 200, 'body' => ['blobs' => []]],
			['status' => 200, 'body' => ['blobs' => [
				['cid' => 'bafkone', 'recordUri' => 'at://' . self::DID . '/app.bsky.feed.post/3kpic'],
				['cid' => 'bafktwo', 'recordUri' => 'at://' . self::DID . '/app.bsky.feed.post/3kpic'],
				['cid' => 'bafkthree', 'recordUri' => 'at://did:plc:someoneelse/app.bsky.feed.post/3kpic'],
				['cid' => 'bafkfour', 'recordUri' => 'at://' . self::DID . '/app.bsky.feed.post/3kgone'],
			]]],
		];

		$this->moves->run($move->id);

		$row = $this->rows[1];
		$this->assertSame([Move::NEEDS_DECISION, Move::STEP_CHECK, 'blobs-missing', 3, 'repo_copied'], [$row->state, $row->step, $row->progress['code'], $row->progress['missing'], $row->progress['stage']]);
		$this->assertSame([[
			'collection' => 'app.bsky.feed.post',
			'rkey' => '3kpic',
			'created_at' => '2026-03-01T10:00:00.000Z',
			'text' => 'my cat',
			'url' => 'https://social.test/@alice/42',
		]], $row->progress['missing_records'], 'the posts that lose them are named, each once, only the account\'s own');
		$this->assertTrue($row->isUnderWay(), 'no other move starts meanwhile');
	}

	public function testAfterMissingPicturesThePersonCopiesAgainOrSwitchesWithout(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->answers['com.atproto.server.checkAccountStatus'] = [
			['status' => 200, 'body' => ['expectedBlobs' => 5, 'importedBlobs' => 2]],
			['status' => 200, 'body' => ['expectedBlobs' => 5, 'importedBlobs' => 4]],
		];
		$this->moves->run($move->id);

		$this->calls = [];
		$this->moves->proceed('alice', false);
		$this->assertSame([Move::RUNNING, Move::STEP_BLOBS], [$this->rows[1]->state, $this->rows[1]->step]);
		$this->moves->run($move->id);
		$this->assertSame(['com.atproto.repo.listMissingBlobs', 'app.bsky.actor.putPreferences', 'com.atproto.server.checkAccountStatus', 'com.atproto.repo.listMissingBlobs'], array_column($this->calls, 'method'), 'copied again and asked again, and which records lose what is missing');
		$this->assertSame([Move::NEEDS_DECISION, 1], [$this->rows[1]->state, $this->rows[1]->progress['missing']]);

		$this->identities->expects($this->once())->method('handOver');
		$this->moves->proceed('alice', true);
		$this->moves->run($move->id);
		$this->assertSame([Move::DONE, 1], [$this->rows[1]->state, $this->rows[1]->progress['accepted_missing']]);
	}

	public function testARepositoryThatIsNotTheOneHereIsCopiedAgain(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->head = new RepoHead(self::DID, 'bafyhead', 'rev2', 12, 0, 0);
		$this->answers['com.atproto.server.checkAccountStatus'] = [['status' => 200, 'body' => ['repoCommit' => 'bafyolder', 'indexedRecords' => 11]]];
		$this->moves->run($move->id);
		$this->assertSame([Move::NEEDS_DECISION, 'repo-incomplete'], [$this->rows[1]->state, $this->rows[1]->progress['code']]);

		$this->moves->proceed('alice', false);
		$this->assertSame(Move::STEP_REPO, $this->rows[1]->step);
	}

	public function testOnlyAStoppedMoveWaitsForADecision(): void {
		$this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');

		$this->expectException(MoveErrorException::class);
		$this->moves->proceed('alice', true);
	}

	public function testPostsWrittenDuringTheMoveGoAlongBeforeTheDidMoves(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->head = new RepoHead(self::DID, 'bafyfirst', 'rev1', 10, 0, 0);
		$this->publisher->expects($this->once())->method('publishBacklog')->with('https://social.test/@alice', 0)
			->willReturnCallback(function (): int {
				// what waited is written to the repository here now
				$this->head = new RepoHead(self::DID, 'bafysecond', 'rev2', 12, 0, 0);

				return 2;
			});
		$this->answers['com.atproto.server.checkAccountStatus'] = [['status' => 200, 'body' => ['repoCommit' => 'bafysecond', 'indexedRecords' => 12]]];
		$this->identities->expects($this->once())->method('handOver');

		$this->moves->run($move->id);

		$imports = array_values(array_filter($this->calls, static fn (array $c): bool => $c['method'] === 'com.atproto.repo.importRepo'));
		$this->assertSame(['the car at bafyfirst', 'the car at bafysecond'], array_column($imports, 'bytes'), 'exported again, its head having moved on');
		$methods = array_column($this->calls, 'method');
		$this->assertLessThan(array_search('com.atproto.identity.getRecommendedDidCredentials', $methods, true), array_search('com.atproto.server.checkAccountStatus', $methods, true));
		$this->assertSame([Move::DONE, 2, 'bafysecond'], [$this->rows[1]->state, $this->rows[1]->progress['posts_during_move'], $this->rows[1]->progress['exported']]);
	}

	public function testARepositoryThatDidNotMoveOnIsNotExportedAgain(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->head = new RepoHead(self::DID, 'bafyfirst', 'rev1', 10, 0, 0);
		$this->answers['com.atproto.server.checkAccountStatus'] = [['status' => 200, 'body' => ['repoCommit' => 'bafyfirst', 'indexedRecords' => 10]]];

		$this->moves->run($move->id);

		$this->assertCount(1, array_filter(array_column($this->calls, 'method'), static fn (string $m): bool => $m === 'com.atproto.repo.importRepo'));
	}

	public function testCallingOffPublishesWhatWaited(): void {
		$this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->publisher->expects($this->once())->method('publishBacklog')->with('https://social.test/@alice', 0);

		$this->moves->cancel('alice');
	}

	public function testOnceMovedTheAccountHereSaysWhereItWentAsChosen(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret', '', false, 'post');
		$this->announcement->expects($this->once())->method('announce')->with('alice', 'post', 'alice.social.test', 'alice.pds.example.com');

		$this->moves->run($move->id);

		$this->assertSame([Move::DONE, true], [$this->rows[1]->state, $this->rows[1]->progress['announced']]);
	}

	public function testAnAnnouncementThatFailsLeavesTheMoveDone(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret', '', false, 'profile');
		$this->announcement->method('announce')->willThrowException(new MoveErrorException('The profile has no free field', 'profile-fields-full'));

		$this->moves->run($move->id);

		$this->assertSame([Move::DONE, false, 'profile-fields-full'], [$this->rows[1]->state, $this->rows[1]->progress['announced'], $this->rows[1]->progress['announce_code']]);
	}

	public function testNothingIsAnnouncedUnlessAsked(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->announcement->expects($this->never())->method('announce');

		$this->moves->run($move->id);
	}

	public function testAnUnknownAnnouncementIsRefusedFirst(): void {
		try {
			$this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret', '', false, 'billboard');
			$this->fail('started');
		} catch (MoveErrorException $e) {
			$this->assertSame('announce-invalid', $e->errorCode);
		}
		$this->assertSame([], $this->calls);
	}

	private function assertRefused(string $code, string $message, string $handle = 'alice.pds.example.com'): void {
		try {
			$this->moves->start('alice', $this->alice, 'pds.example.com', $handle, 'a@example.org', 'pw');
			$this->fail('started');
		} catch (MoveErrorException $e) {
			$this->assertSame($code, $e->errorCode);
			$this->assertStringContainsString($message, $e->getMessage());
		}
	}

	public function testTheMoveCopiesHandsOverActivatesAndSwitchesOffHere(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$missing = Cid::forRaw('pixels')->toString();
		$this->answers['com.atproto.repo.listMissingBlobs'] = [
			['status' => 200, 'body' => ['blobs' => [['cid' => $missing, 'recordUri' => 'at://x']], 'cursor' => 'c1']],
			['status' => 200, 'body' => ['blobs' => [['cid' => Cid::forRaw('never here')->toString()]]]],
		];
		$credentials = ['rotationKeys' => ['did:key:zTheirs'], 'verificationMethods' => ['atproto' => 'did:key:zSigning'], 'alsoKnownAs' => ['at://alice.pds.example.com'], 'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => self::PDS]]];
		$this->answers['com.atproto.identity.getRecommendedDidCredentials'] = [['status' => 200, 'body' => $credentials]];
		$this->identities->expects($this->once())->method('handOver')->with($this->alice, $credentials);
		$this->identities->expects($this->once())->method('markMovedAway')->with($this->alice);

		$this->moves->run($move->id);

		$this->assertSame(['com.atproto.server.describeServer', 'com.atproto.server.createAccount', 'com.atproto.repo.importRepo', 'com.atproto.repo.listMissingBlobs', 'com.atproto.repo.uploadBlob', 'com.atproto.repo.listMissingBlobs', 'app.bsky.actor.putPreferences', 'com.atproto.server.checkAccountStatus', 'com.atproto.identity.getRecommendedDidCredentials', 'com.atproto.server.activateAccount'], array_column($this->calls, 'method'), 'in this order: the DID moves only once everything is there');
		$this->assertSame('the car at nothing', $this->call('com.atproto.repo.importRepo')['bytes']);
		$this->assertSame('pixels', $this->call('com.atproto.repo.uploadBlob')['bytes']);
		$this->assertSame(['limit' => 500, 'cursor' => 'c1'], $this->calls[5]['query']);
		$this->assertSame('access-1', $this->call('com.atproto.server.activateAccount')['token']);
		$this->assertNull($this->call('com.atproto.server.activateAccount')['json'], 'activateAccount takes no body');
		$this->assertNull($this->call('com.atproto.server.activateAccount')['bytes']);
		$this->assertSame(Move::DONE, $this->rows[1]->state);
		$this->assertSame('', $this->rows[1]->session, 'the other server\'s session is not kept');
	}

	/**
	 * The other PDS is the person's to name: a cursor it named before, or
	 * a page that names no blob it did not name before, ends the copy, and
	 * a blob is uploaded once.
	 */
	public function testAListOfMissingBlobsThatGoesRoundEndsTheCopy(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$missing = ['cid' => Cid::forRaw('pixels')->toString()];
		$this->answers['com.atproto.repo.listMissingBlobs'] = [
			['status' => 200, 'body' => ['blobs' => [$missing], 'cursor' => 'a']],
			['status' => 200, 'body' => ['blobs' => [['cid' => Cid::forRaw('never here')->toString()]], 'cursor' => 'b']],
			['status' => 200, 'body' => ['blobs' => [['cid' => Cid::forRaw('nor here')->toString()]], 'cursor' => 'a']],
			['status' => 200, 'body' => ['blobs' => [$missing], 'cursor' => 'c']],
			['status' => 200, 'body' => ['blobs' => [$missing], 'cursor' => 'd']],
		];
		$this->moves->run($move->id);
		$methods = array_column($this->calls, 'method');
		$this->assertSame(3, count(array_keys($methods, 'com.atproto.repo.listMissingBlobs', true)), 'a cursor named before ends it');
		$this->assertSame(1, count(array_keys($methods, 'com.atproto.repo.uploadBlob', true)));

		$this->calls = [];
		$this->answers['com.atproto.repo.listMissingBlobs'] = [
			['status' => 200, 'body' => ['blobs' => [$missing], 'cursor' => 'x']],
			['status' => 200, 'body' => ['blobs' => [$missing], 'cursor' => 'y']],
			['status' => 200, 'body' => ['blobs' => [$missing], 'cursor' => 'z']],
		];
		$this->rows[1]->step = Move::STEP_BLOBS;
		$this->rows[1]->state = Move::RUNNING;
		$this->moves->run($move->id);
		$methods = array_column($this->calls, 'method');
		$this->assertSame(2, count(array_keys($methods, 'com.atproto.repo.listMissingBlobs', true)), 'a page that names nothing new ends it');
		$this->assertSame(1, count(array_keys($methods, 'com.atproto.repo.uploadBlob', true)));
	}

	public function testAFailedStepIsStartedAgainFromThere(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->answers['com.atproto.repo.listMissingBlobs'] = [['status' => 502, 'body' => ['error' => 'UpstreamFailure']]];
		$this->moves->run($move->id);
		$this->assertNotContains('com.atproto.identity.getRecommendedDidCredentials', array_column($this->calls, 'method'), 'the DID did not move');
		$this->assertSame(Move::FAILED, $this->rows[1]->state);
		$this->assertSame(Move::STEP_BLOBS, $this->rows[1]->step);
		$this->assertStringContainsString('UpstreamFailure', $this->rows[1]->error);

		$this->calls = [];
		$this->moves->retry('alice');
		$this->moves->run($move->id);
		$this->assertNotContains('com.atproto.repo.importRepo', array_column($this->calls, 'method'), 'the repository is there already');
		$this->assertSame(Move::DONE, $this->rows[1]->state);
	}

	public function testAnExpiredSessionThereIsRefreshedOnce(): void {
		$move = $this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');
		$this->answers['com.atproto.repo.importRepo'] = [['status' => 400, 'body' => ['error' => 'ExpiredToken']], ['status' => 200, 'body' => []]];
		$this->answers['com.atproto.server.refreshSession'] = [['status' => 200, 'body' => ['accessJwt' => 'access-2', 'refreshJwt' => 'refresh-2']]];

		$this->moves->run($move->id);

		$this->assertSame('refresh-1', $this->call('com.atproto.server.refreshSession')['token']);
		$this->assertSame('access-2', $this->call('com.atproto.server.activateAccount')['token']);
	}

	public function testOnlyAMoveAwayIsStartedAgainHere(): void {
		$this->rows[1] = new Move(1, self::DID, 'alice', Move::INBOUND, 'https://atproto.brid.gy', '', 'alice.ap.brid.gy', Move::STEP_REPO, Move::FAILED);

		$this->expectException(MoveErrorException::class);
		$this->moves->retry('alice');
	}

	public function testOneMoveAtATime(): void {
		$this->moves->start('alice', $this->alice, 'pds.example.com', 'alice.pds.example.com', 'alice@example.org', 'secret');

		try {
			$this->moves->start('alice', $this->alice, 'pds.example.com', 'alice2.pds.example.com', 'alice@example.org', 'secret');
			$this->fail('a second move started');
		} catch (MoveErrorException $e) {
			$this->assertSame('move-in-progress', $e->errorCode);
		}
	}
}
