<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Crypto\PublicKey;
use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Identity\HandleVerifier;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\Mnemonic;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Protocol\Commit;
use OCA\Social\Atproto\Protocol\Mst;
use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCA\Social\Exceptions\AtprotoKeysSealedException;
use OCA\Social\Exceptions\AtprotoPlcRefusedException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Security\PrivateKeyCipher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Making an identity: the handle, the genesis operation and the DID it
 * defines, what is stored sealed, what is logged before it is sent, and
 * what happens when the directory is down, refuses an operation or
 * nullified one: the log is a queue per DID whose `prev` is the
 * directory's confirmed head, a refused operation is dead and the next
 * one follows the head instead, and repair() reads the directory's audit
 * log before it sends anything again.
 */
#[AllowMockObjectsWithoutExpectations]
class IdentityServiceTest extends TestCase {
	/** @var AtprotoIdentityRequest&MockObject */
	private AtprotoIdentityRequest $identityRequest;
	/** @var AtprotoPlcLogRequest&MockObject */
	private AtprotoPlcLogRequest $plcLog;
	/** @var PlcClient&MockObject */
	private PlcClient $plc;
	/** @var EventService&MockObject */
	private EventService $events;
	/** @var RepositoryService&MockObject */
	private RepositoryService $repositories;
	/** @var AtprotoBlobRequest&MockObject */
	private AtprotoBlobRequest $blobs;
	private AtprotoConfig $config;
	private InstanceKeyService $instanceKeys;
	private PrivateKeyCipher $cipher;
	private PrivateKey $rotation;
	/** the rotation key in use; the one before it stays in $rotation */
	private PrivateKey $currentRotation;
	private IdentityService $service;
	/** @var array<string, Identity> */
	private array $stored = [];
	/** @var array<int, array{id: int, did: string, cid: string, operation: array, creation: int, sent: int, confirmed: int, dead: int, reason: string, removed?: bool}> the PLC log, row id = index + 1 */
	private array $logged = [];
	/** @var array<string, Identity> the rows found by actor id */
	private array $byActor = [];
	/** @var list<string> the domains that name their DID */
	private array $verified = [];
	private HandleVerifier $handles;

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('handleHost')->willReturn('social.test');
		$config->method('pdsEndpoint')->willReturn('https://social.test');
		$config->method('serviceDid')->willReturn('did:web:social.test');

		$this->identityRequest = $this->createMock(AtprotoIdentityRequest::class);
		$this->identityRequest->method('handleExists')->willReturnCallback(fn (string $handle): bool => $handle === 'taken.social.test');
		$this->identityRequest->method('create')->willReturnCallback(function (string $actorId, string $did, string $handle, string $sealed, string $public, string $recovery, string $state = Identity::STATE_ACTIVE): int {
			$this->stored[$did] = new Identity(count($this->stored) + 1, $actorId, $did, $handle, $sealed, $public, $recovery, $state, '', 1700000000);

			return count($this->stored);
		});
		$this->identityRequest->method('setState')->willReturnCallback(function (string $did, string $state): void {
			$was = $this->stored[$did] ?? null;
			if ($was !== null) {
				$this->stored[$did] = new Identity($was->id, $was->actorId, $was->did, $was->handle, $was->sealedSigningKey, $was->signingPublic, $was->recoveryPublic, $state, $was->movedFromPds, $was->creation, $was->assignedHandle, $was->customHandle);
			}
		});
		$this->identityRequest->method('getByDid')->willReturnCallback(fn (string $did): Identity => $this->stored[$did] ?? throw new AtprotoIdentityNotFoundException());
		$this->identityRequest->method('getByActorId')->willReturnCallback(fn (string $id): Identity => $this->byActor[$id] ?? throw new AtprotoIdentityNotFoundException());
		$this->identityRequest->method('getByHandle')->willReturnCallback(function (string $handle): Identity {
			foreach ($this->stored as $identity) {
				if ($identity->handle === $handle) {
					return $identity;
				}
			}
			throw new AtprotoIdentityNotFoundException();
		});
		$this->handles = $this->createMock(HandleVerifier::class);
		$this->handles->method('refusal')->willReturnCallback(static fn (string $handle): string => str_ends_with($handle, '.social.test') ? 'no' : '');
		$this->handles->method('verify')->willReturnCallback(fn (string $handle): string => in_array($handle, $this->verified, true) ? 'dns' : '');

		$this->identityRequest->method('setRecoveryPublic')->willReturnCallback(function (string $did, string $recovery): void {
			$was = $this->stored[$did];
			$this->stored[$did] = new Identity($was->id, $was->actorId, $was->did, $was->handle, $was->sealedSigningKey, $was->signingPublic, $recovery, $was->state, $was->movedFromPds, $was->creation, $was->assignedHandle, $was->customHandle);
		});
		$this->identityRequest->method('setCustomHandle')->willReturnCallback(function (string $did, string $custom): void {
			$was = $this->stored[$did];
			$this->stored[$did] = new Identity($was->id, $was->actorId, $was->did, $custom !== '' ? $custom : $was->assignedHandle(), $was->sealedSigningKey, $was->signingPublic, $was->recoveryPublic, $was->state, $was->movedFromPds, $was->creation, $was->assignedHandle(), $custom);
		});

		$this->plcLog = $this->createMock(AtprotoPlcLogRequest::class);
		$this->plcLog->method('record')->willReturnCallback(function (string $did, string $cid, array $operation): int {
			$this->logged[] = ['id' => count($this->logged) + 1, 'did' => $did, 'cid' => $cid, 'operation' => $operation, 'creation' => 1, 'sent' => 0, 'confirmed' => 0, 'dead' => 0, 'reason' => ''];

			return count($this->logged);
		});
		$this->plcLog->method('rewrite')->willReturnCallback(function (int $id, string $cid, array $operation): void {
			$this->logged[$id - 1]['cid'] = $cid;
			$this->logged[$id - 1]['operation'] = $operation;
		});
		$this->plcLog->method('markSent')->willReturnCallback(function (int $id): void {
			$this->logged[$id - 1]['sent'] = 1;
		});
		$this->plcLog->method('markConfirmed')->willReturnCallback(function (int $id): void {
			$this->logged[$id - 1]['confirmed'] = 1;
		});
		$this->plcLog->method('markDead')->willReturnCallback(function (int $id, string $reason): void {
			$this->logged[$id - 1]['dead'] = 1;
			$this->logged[$id - 1]['reason'] = $reason;
		});
		$this->plcLog->method('remove')->willReturnCallback(function (int $id): void {
			$this->logged[$id - 1]['removed'] = true;
		});
		$this->plcLog->method('getByDid')->willReturnCallback(fn (string $did): array => $this->rows(static fn (array $row): bool => $row['did'] === $did));
		$this->plcLog->method('getPending')->willReturnCallback(fn (string $did): array => $this->rows(static fn (array $row): bool => $row['did'] === $did && $row['confirmed'] === 0 && $row['dead'] === 0));
		$this->plcLog->method('getUnconfirmed')->willReturnCallback(fn (int $limit = 100): array => array_slice($this->rows(static fn (array $row): bool => $row['confirmed'] === 0 && $row['dead'] === 0), 0, $limit));
		$this->plcLog->method('latestCid')->willReturnCallback(fn (string $did): string => $this->lastCid($did, false));
		$this->plcLog->method('confirmedCid')->willReturnCallback(fn (string $did): string => $this->lastCid($did, true));

		$this->rotation = PrivateKey::generate(Curve::K256);
		$this->currentRotation = $this->rotation;
		$instanceKeys = $this->createMock(InstanceKeyService::class);
		$instanceKeys->method('rotationKey')->willReturnCallback(fn (): PrivateKey => $this->currentRotation);
		$instanceKeys->method('rotationKeys')->willReturnCallback(fn (): array => $this->currentRotation === $this->rotation ? [$this->rotation] : [$this->currentRotation, $this->rotation]);
		$instanceKeys->method('rotationDidKeys')->willReturnCallback(fn (): array => $this->currentRotation === $this->rotation
			? [$this->rotation->didKey() => false]
			: [$this->currentRotation->didKey() => false, $this->rotation->didKey() => true]);
		$instanceKeys->method('serviceKey')->willReturn(PrivateKey::generate(Curve::K256));

		$cipher = $this->createMock(PrivateKeyCipher::class);
		$cipher->method('seal')->willReturnCallback(static fn (string $secret): string => 'sealed:' . base64_encode($secret));
		$cipher->method('open')->willReturnCallback(static fn (string $stored): string => str_starts_with($stored, 'sealed:') ? (string)base64_decode(substr($stored, 7)) : '');

		$this->plc = $this->createMock(PlcClient::class);
		$this->events = $this->createMock(EventService::class);
		$this->repositories = $this->createMock(RepositoryService::class);
		$this->blobs = $this->createMock(AtprotoBlobRequest::class);

		$this->config = $config;
		$this->instanceKeys = $instanceKeys;
		$this->cipher = $cipher;
		$this->setUpService();
	}

	private function setUpService(): void {
		$this->service = new IdentityService(
			$this->config, $this->identityRequest, $this->plcLog, $this->blobs, $this->instanceKeys, $this->plc, $this->events, $this->repositories, $this->cipher, $this->handles, new NullLogger(),
		);
	}

	public function testAnIdentityIsMadeRegisteredAndAnnounced(): void {
		$this->plc->expects($this->once())->method('submit')->with(
			$this->callback(static fn (string $did): bool => str_starts_with($did, 'did:plc:') && strlen($did) === 32),
			$this->callback(fn (array $operation): bool => $operation['type'] === 'plc_operation'
				&& $operation['rotationKeys'] === [$this->rotation->didKey()]
				&& $operation['alsoKnownAs'] === ['at://alice-smith.social.test']
				&& $operation['services']['atproto_pds']['endpoint'] === 'https://social.test'
				&& $operation['prev'] === null
				&& PlcOperation::verify($operation, $this->rotation->publicKey())),
		);
		$order = [];
		$this->plcLog->expects($this->once())->method('markConfirmed')->willReturnCallback(function (int $id) use (&$order): void {
			$this->logged[$id - 1]['confirmed'] = 1;
			$order[] = 'confirmed';
		});
		$this->events->expects($this->once())->method('identity')->with($this->anything(), 'alice-smith.social.test')->willReturnCallback(static function () use (&$order): int {
			$order[] = 'identity';

			return 1;
		});

		$identity = $this->service->forActor(self::actor('Alice.Smith'));

		$this->assertNotNull($identity);
		$this->assertSame(['confirmed', 'identity'], $order, 'announced once the directory holds the DID');
		$this->assertTrue($identity->isActive(), 'on Bluesky from the moment the directory took the genesis');
		$this->assertSame('alice-smith.social.test', $identity->handle);
		$this->assertSame($identity->did, PlcOperation::didOf($this->logged[0]['operation']), 'the DID is the hash of the logged genesis');
		$this->assertStringStartsWith('sealed:', $identity->sealedSigningKey);
		$this->assertSame($this->service->signingKey($identity)->didKey(), $identity->signingPublic, 'the sealed secret opens to the key the public half names');
		$this->assertSame('', $identity->recoveryPublic, 'no recovery key until the person asks for one');
	}

	public function testATakenHandleGetsANumber(): void {
		$identity = $this->service->forActor(self::actor('taken'));

		$this->assertSame('taken-2.social.test', $identity?->handle);
	}

	/**
	 * The directory is down: the identity exists here, pending — not on
	 * Bluesky, not announced — and the repair that gets the genesis
	 * through makes it active and announces it then.
	 */
	public function testADirectoryThatIsDownLeavesTheIdentityPendingForRepair(): void {
		$down = true;
		$this->plc->method('submit')->willReturnCallback(static function () use (&$down): void {
			if ($down) {
				throw new AtprotoException('down');
			}
		});
		$this->plcLog->expects($this->once())->method('markSent');
		$this->plcLog->expects($this->never())->method('markConfirmed');
		$this->events->expects($this->never())->method('identity');

		$identity = $this->service->forActor(self::actor('bob'));

		$this->assertNotNull($identity, 'the identity exists here whatever the directory said');
		$this->assertSame(Identity::STATE_PENDING, $identity->state);
		$this->assertFalse($identity->isActive(), 'nothing is published for it yet');
		$this->assertNull($this->service->activeForActor(self::actor('bob')));
		$this->assertSame($identity, $this->byActor[$identity->actorId] = $this->stored[$identity->did], 'kept, not made again');
		$this->assertSame($identity->did, $this->service->forActor(self::actor('bob'))?->did);
	}

	public function testRepairMakesAPendingIdentityActiveAndAnnouncesIt(): void {
		$this->plc->method('submit')->willThrowException(new AtprotoException('down'));
		$identity = $this->service->forActor(self::actor('late'));
		$this->assertNotNull($identity);
		$this->assertSame(Identity::STATE_PENDING, $identity->state);

		$this->plc = $this->createMock(PlcClient::class);
		$this->setUpService();
		$this->plc->method('auditLog')->willReturn([]);
		$this->events->expects($this->once())->method('identity')->with($identity->did, 'late.social.test');

		$this->assertSame(1, $this->service->repair());
		$this->assertSame(Identity::STATE_ACTIVE, $this->stored[$identity->did]->state);
	}

	/**
	 * A handle change is announced once the directory holds it, not when
	 * it is written here.
	 */
	public function testAHandleChangeIsAnnouncedOnceTheDirectoryTookIt(): void {
		$identity = $this->service->forActor(self::actor('renamed'));
		$this->assertNotNull($identity);
		$down = true;
		$this->plc->method('submit')->willReturnCallback(static function () use (&$down): void {
			if ($down) {
				throw new AtprotoException('down');
			}
		});
		$this->plc->method('auditLog')->willReturn([['cid' => $this->logged[0]['cid'], 'operation' => $this->logged[0]['operation'], 'nullified' => false]]);
		$announced = [];
		$this->events->method('identity')->willReturnCallback(static function (string $did, string $handle) use (&$announced): int {
			$announced[] = $handle;

			return 1;
		});

		$updated = $this->service->useCustomHandle($identity, 'renamed.example.org');
		$this->assertSame('renamed.example.org', $updated->handle, 'the row has it');
		$this->assertSame([], $announced, 'not announced while the directory does not hold it');

		$down = false;
		$this->service->repair();
		$this->assertSame(['renamed.example.org'], $announced);
	}

	public function testRepairResendsWhatWasNotConfirmed(): void {
		$this->plcLog->record('did:plc:x', 'bafy', ['type' => 'plc_operation', 'prev' => null]);
		$this->plc->expects($this->once())->method('auditLog')->with('did:plc:x')->willReturn([]);
		$this->plc->expects($this->once())->method('submit')->with('did:plc:x', ['type' => 'plc_operation', 'prev' => null]);
		$this->plcLog->expects($this->once())->method('markConfirmed')->with(1);

		$this->assertSame(1, $this->service->repair());
	}

	/**
	 * The directory could not be asked about A: A waits, B is queued
	 * behind it and never tried on its own, and the repair sends both in
	 * order once the directory answers, B as it was built, since A is the
	 * head it follows.
	 */
	public function testAnOperationTheDirectoryCouldNotTakeHoldsTheOnesBehindIt(): void {
		$identity = $this->service->forActor(self::actor('queued'));
		$this->assertNotNull($identity);
		$down = true;
		$submitted = [];
		$this->plc->method('submit')->willReturnCallback(static function (string $did, array $operation) use (&$submitted, &$down): void {
			$submitted[] = $operation;
			if ($down) {
				throw new AtprotoException('PLC directory could not be reached');
			}
		});
		$this->plc->method('auditLog')->willReturn([['cid' => $this->logged[0]['cid'], 'operation' => $this->logged[0]['operation'], 'nullified' => false]]);

		$this->service->issueRecoveryKey($identity);
		$this->service->useCustomHandle($this->stored[$identity->did], 'queued.example.org');

		$this->assertSame([$this->logged[1]['operation'], $this->logged[1]['operation']], $submitted, 'A is tried when queued and again when B is queued; B is never tried while A is pending');
		$this->assertSame([1, 1, 0], array_column($this->logged, 'sent'));
		$this->assertSame([1, 0, 0], array_column($this->logged, 'confirmed'));
		$this->assertSame($this->logged[1]['cid'], $this->logged[2]['operation']['prev'], 'B follows A');
		$this->assertSame($this->logged[0]['cid'], $this->logged[1]['operation']['prev'], 'A follows the genesis');

		$down = false;
		$cidOfB = $this->logged[2]['cid'];
		$this->assertSame(2, $this->service->repair());
		$this->assertSame([1, 1, 1], array_column($this->logged, 'confirmed'));
		$this->assertSame($cidOfB, $this->logged[2]['cid'], 'B was sent as it was built: its prev was the head');
		$this->assertSame([$this->logged[1]['operation'], $this->logged[2]['operation']], array_slice($submitted, 2));
	}

	/**
	 * The directory refuses A: A is dead, with the directory's reason, and
	 * B — built on A — is signed again on the confirmed head and sent.
	 */
	public function testARefusedOperationIsDeadAndTheNextFollowsTheConfirmedHead(): void {
		$identity = $this->service->forActor(self::actor('refused'));
		$this->assertNotNull($identity);
		$answer = static fn (): never => throw new AtprotoException('PLC directory could not be reached');
		$submitted = [];
		$this->plc->method('submit')->willReturnCallback(static function (string $did, array $operation) use (&$submitted, &$answer): void {
			$submitted[] = $operation;
			$answer();
		});
		$this->plc->method('auditLog')->willReturn([['cid' => $this->logged[0]['cid'], 'operation' => $this->logged[0]['operation'], 'nullified' => false]]);
		$this->service->issueRecoveryKey($identity);
		$this->service->useCustomHandle($this->stored[$identity->did], 'refused.example.org');
		$recovery = $this->stored[$identity->did]->recoveryPublic;
		$this->assertSame([$this->rotation->didKey(), $recovery], $this->logged[1]['operation']['rotationKeys']);
		$this->assertSame([$this->rotation->didKey(), $recovery], $this->logged[2]['operation']['rotationKeys'], 'B carries the recovery key A added');

		$tried = 0;
		$answer = static function () use (&$tried): void {
			if ($tried++ === 0) {
				throw new AtprotoPlcRefusedException('PLC directory refused the operation (400): Invalid signature on op');
			}
		};
		$this->assertSame(1, $this->service->repair());

		$this->assertSame([1, 0, 1], array_column($this->logged, 'confirmed'));
		$this->assertSame([0, 1, 0], array_column($this->logged, 'dead'));
		$this->assertSame('PLC directory refused the operation (400): Invalid signature on op', $this->logged[1]['reason']);
		$sent = $this->logged[2]['operation'];
		$this->assertSame($this->logged[0]['cid'], $sent['prev'], 'B follows the genesis now, not the dead A');
		$this->assertSame(['at://refused.example.org'], $sent['alsoKnownAs']);
		$this->assertTrue(PlcOperation::verify($sent, $this->rotation->publicKey()), 'signed again');
		$this->assertSame(PlcOperation::cid($sent)->toString(), $this->logged[2]['cid'], 'the row carries the CID of what was sent');
		$this->assertSame($sent, end($submitted));
		$this->assertSame(['at://refused.example.org'], $this->service->document($this->stored[$identity->did])['alsoKnownAs'], 'the document is built from the live operations');

		$this->assertSame(0, $this->service->repair(), 'a dead operation is never sent again');
		$this->assertCount(4, $submitted);
	}

	/**
	 * A head that names only a retired instance key — the operation that
	 * rotated it is dead — is followed with that key.
	 */
	public function testARetiredInstanceKeySignsWhenTheHeadStillNamesIt(): void {
		$identity = $this->service->forActor(self::actor('rotated'));
		$this->assertNotNull($identity);
		$this->plc->method('auditLog')->willReturn([['cid' => $this->logged[0]['cid'], 'operation' => $this->logged[0]['operation'], 'nullified' => false]]);
		$this->plc->method('submit')->willReturnCallback(function (string $did, array $operation): void {
			if (count($this->logged) === 2) {
				throw new AtprotoPlcRefusedException('PLC directory refused the operation (400): nope');
			}
		});
		$new = PrivateKey::generate(Curve::K256);
		$this->service->rotateInstanceKey($identity, $new, $this->rotation);
		$this->currentRotation = $new;
		$this->assertSame(1, $this->logged[1]['dead']);

		$this->service->useCustomHandle($this->stored[$identity->did], 'rotated.example.org');

		$sent = $this->logged[2]['operation'];
		$this->assertSame(1, $this->logged[2]['confirmed']);
		$this->assertSame($this->logged[0]['cid'], $sent['prev']);
		$this->assertSame([$new->didKey()], $sent['rotationKeys'], 'the operation names the key in use');
		$this->assertTrue(PlcOperation::verify($sent, $this->rotation->publicKey()), 'signed with the key the head names');
		$this->assertFalse(PlcOperation::verify($sent, $new->publicKey()));
	}

	/**
	 * The directory's audit log is the truth: an operation it nullified is
	 * dead, one it holds that the log does not (the person's own, signed
	 * with their recovery key) is taken in as confirmed, the identity row
	 * takes the handle it names, and an operation that cannot follow the
	 * new head — no key of this server is named any more — is dead too.
	 */
	public function testANullifiedOperationIsDeadAndTheIdentityFollowsTheDirectory(): void {
		$this->verified = ['nullified.example.org'];
		$identity = $this->service->forActor(self::actor('nullified'));
		$this->assertNotNull($identity);
		$this->service->issueRecoveryKey($identity);
		$withRecovery = $this->stored[$identity->did];
		$this->assertSame(1, $this->logged[1]['confirmed']);
		$theirs = PlcOperation::sign(
			PlcOperation::build([$withRecovery->recoveryPublic], $withRecovery->signingPublic, 'nullified.example.org', 'https://social.test', $this->logged[0]['cid']),
			PrivateKey::generate(Curve::K256),
		);
		$this->plc->method('auditLog')->willReturn([
			['cid' => $this->logged[0]['cid'], 'operation' => $this->logged[0]['operation'], 'nullified' => false],
			['cid' => $this->logged[1]['cid'], 'operation' => $this->logged[1]['operation'], 'nullified' => true],
			['cid' => PlcOperation::cid($theirs)->toString(), 'operation' => $theirs, 'nullified' => false],
		]);
		$this->plc->method('submit')->willThrowException(new AtprotoException('PLC directory could not be reached'));
		$this->service->useCustomHandle($withRecovery, 'mine.example.org');
		$this->assertSame(0, $this->logged[2]['confirmed']);
		$this->identityRequest->expects($this->once())->method('setCustomHandle')->with($identity->did, 'nullified.example.org');
		$this->identityRequest->expects($this->never())->method('setRecoveryPublic');

		$this->assertSame(0, $this->service->repair());

		$this->assertSame([0, 1, 1, 0], array_column($this->logged, 'dead'));
		$this->assertSame('nullified by the directory', $this->logged[1]['reason']);
		$this->assertSame($theirs, $this->logged[3]['operation'], 'the directory\'s operation is in the log');
		$this->assertSame(1, $this->logged[3]['confirmed']);
		$this->assertStringContainsString('no rotation key of this server', $this->logged[2]['reason']);
		$this->assertSame(['at://nullified.example.org'], $this->service->document($withRecovery)['alsoKnownAs']);
	}

	/**
	 * A handle the directory's head names is taken as the account's only
	 * when its domain names the DID, as a custom handle set here must:
	 * anyone holding a rotation key can write any domain there.
	 */
	public function testAHandleTheDirectoryNamesIsNotTakenUnlessItNamesTheDid(): void {
		$identity = $this->service->forActor(self::actor('claimed'));
		$this->assertNotNull($identity);
		$this->service->issueRecoveryKey($identity);
		$withRecovery = $this->stored[$identity->did];
		$theirs = PlcOperation::sign(
			PlcOperation::build([$withRecovery->recoveryPublic], $withRecovery->signingPublic, 'victim.example.org', 'https://social.test', $this->logged[1]['cid']),
			PrivateKey::generate(Curve::K256),
		);
		$this->plc->method('auditLog')->willReturn([
			['cid' => $this->logged[0]['cid'], 'operation' => $this->logged[0]['operation'], 'nullified' => false],
			['cid' => $this->logged[1]['cid'], 'operation' => $this->logged[1]['operation'], 'nullified' => false],
			['cid' => PlcOperation::cid($theirs)->toString(), 'operation' => $theirs, 'nullified' => false],
		]);
		$this->plc->method('submit')->willThrowException(new AtprotoException('PLC directory could not be reached'));
		$this->service->useCustomHandle($withRecovery, 'mine.example.org');
		$this->identityRequest->expects($this->never())->method('setCustomHandle');

		$this->service->repair();

		$this->assertSame(1, end($this->logged)['confirmed'], 'the directory\'s operation is the head');
		$this->assertSame('mine.example.org', $this->stored[$identity->did]->customHandle);
	}

	/**
	 * The directory's head names neither the recovery key nor any key of
	 * this server: the row drops the recovery key, and the pending
	 * operation that would have listed it again cannot follow the head.
	 */
	public function testARecoveryKeyTheDirectoryNoLongerListsIsDropped(): void {
		$identity = $this->service->forActor(self::actor('dropped'));
		$this->assertNotNull($identity);
		$this->service->issueRecoveryKey($identity);
		$theirs = PlcOperation::sign(
			PlcOperation::build([PrivateKey::generate(Curve::K256)->didKey()], $identity->signingPublic, 'dropped.social.test', 'https://social.test', $this->logged[0]['cid']),
			PrivateKey::generate(Curve::K256),
		);
		$this->plc->method('auditLog')->willReturn([
			['cid' => $this->logged[0]['cid'], 'operation' => $this->logged[0]['operation'], 'nullified' => false],
			['cid' => PlcOperation::cid($theirs)->toString(), 'operation' => $theirs, 'nullified' => false],
		]);
		$this->plc->method('submit')->willThrowException(new AtprotoException('PLC directory could not be reached'));
		$this->service->useCustomHandle($this->stored[$identity->did], 'dropped.example.org');
		$this->identityRequest->expects($this->once())->method('setRecoveryPublic')->with($identity->did, '');
		$this->identityRequest->expects($this->once())->method('setCustomHandle')->with($identity->did, '');

		$this->assertSame(0, $this->service->repair());

		$this->assertSame([0, 1, 1, 0], array_column($this->logged, 'dead'));
		$this->assertSame('not in the directory\'s log', $this->logged[1]['reason'], 'confirmed here, not held there');
		$this->assertStringContainsString('no rotation key of this server', $this->logged[2]['reason']);
	}

	public function testAHandOverWaitsForAnOperationTheDirectoryHasNotConfirmed(): void {
		$identity = $this->service->forActor(self::actor('waiting'));
		$this->assertNotNull($identity);
		$this->plc->method('submit')->willThrowException(new AtprotoException('PLC directory could not be reached'));
		$this->service->issueRecoveryKey($identity);
		$this->plcLog->expects($this->never())->method('remove');

		try {
			$this->service->handOver($this->stored[$identity->did], [
				'rotationKeys' => [PrivateKey::generate(Curve::K256)->didKey()],
				'verificationMethods' => ['atproto' => PrivateKey::generate(Curve::K256)->didKey()],
				'alsoKnownAs' => ['at://waiting.pds.example.com'],
				'services' => ['atproto_pds' => ['endpoint' => 'https://pds.example.com']],
			]);
			$this->fail('handed over');
		} catch (AtprotoException $e) {
			$this->assertStringContainsString('not confirmed yet', $e->getMessage());
		}
		$this->assertCount(2, $this->logged, 'nothing was logged for the hand-over');
	}

	public function testARecoveryKeyIsAddedSecondAndThePhraseIsTheOnlyCopy(): void {
		$identity = $this->service->forActor(self::actor('carol'));
		$this->assertNotNull($identity);
		$submitted = [];
		$this->plc->method('submit')->willReturnCallback(static function (string $did, array $operation) use (&$submitted): void {
			$submitted[] = $operation;
		});
		$this->identityRequest->expects($this->once())->method('setRecoveryPublic')->with($identity->did, $this->stringStartsWith('did:key:zQ3sh'));

		$phrase = $this->service->issueRecoveryKey($identity);

		$this->assertCount(12, explode(' ', $phrase));
		$update = end($submitted);
		$this->assertSame($this->rotation->didKey(), $update['rotationKeys'][0], 'the instance key keeps the first place');
		$this->assertSame(\OCA\Social\Atproto\Identity\Mnemonic::keyFromPhrase($phrase)->didKey(), $update['rotationKeys'][1]);
		$this->assertSame($this->logged[0]['cid'], $update['prev'], 'the update follows the genesis');
		$this->assertTrue(PlcOperation::verify($update, $this->rotation->publicKey()));
	}

	/**
	 * An identity whose DID the person ranks first, as a move here leaves
	 * it (RotationKeys::forMoveHere()): the confirmed head lists $keys.
	 *
	 * @param list<string> $keys
	 */
	private function identityWithHead(string $name, array $keys): Identity {
		$identity = $this->service->forActor(self::actor($name));
		$this->assertNotNull($identity);
		$head = PlcOperation::sign(PlcOperation::build($keys, $identity->signingPublic, $identity->handle, 'https://social.test', $this->logged[count($this->logged) - 1]['cid']), $this->rotation);
		$id = $this->plcLog->record($identity->did, PlcOperation::cid($head)->toString(), $head);
		$this->plcLog->markSent($id);
		$this->plcLog->markConfirmed($id);

		return $this->stored[$identity->did];
	}

	private function lastOperation(): array {
		return $this->logged[count($this->logged) - 1]['operation'];
	}

	/** A handle change carries the keys the head lists, the person's first, untouched. */
	public function testAHandleChangeKeepsTheKeysThePersonPutFirst(): void {
		$user = PrivateKey::generate(Curve::K256)->didKey();
		$identity = $this->identityWithHead('first', [$user, $this->rotation->didKey()]);

		$this->service->useCustomHandle($identity, 'first.example.org');

		$this->assertSame([$user, $this->rotation->didKey()], $this->lastOperation()['rotationKeys']);
		$this->assertSame(['at://first.example.org'], $this->lastOperation()['alsoKnownAs']);
		$this->assertSame(1, $this->logged[count($this->logged) - 1]['confirmed']);
	}

	/** A rotation of the instance key puts the new key where the old one stood, and only there. */
	public function testAnInstanceKeyRotationReplacesOnlyThisServersKeyInPlace(): void {
		$user = PrivateKey::generate(Curve::K256)->didKey();
		$other = PrivateKey::generate(Curve::K256)->didKey();
		$identity = $this->identityWithHead('rotating', [$user, $this->rotation->didKey(), $other]);
		$new = PrivateKey::generate(Curve::K256);
		$this->currentRotation = $new;

		$this->service->rotateInstanceKey($identity, $new, $this->rotation);

		$operation = $this->lastOperation();
		$this->assertSame([$user, $new->didKey(), $other], $operation['rotationKeys']);
		$this->assertTrue(PlcOperation::verify($operation, $this->rotation->publicKey()), 'the old key signs the handover');

		$this->service->refresh($this->stored[$identity->did]);
		$this->assertSame([$user, $new->didKey(), $other], $this->lastOperation()['rotationKeys'], 'and later operations keep it so');
	}

	/**
	 * A recovery phrase adds its key without dropping the person's: above
	 * this server's key where the person ranks first, and a new phrase
	 * takes the place of the one it replaces.
	 */
	public function testARecoveryKeyIsAddedBesideThePersonsKeysAndReplacedInPlace(): void {
		$user = PrivateKey::generate(Curve::K256)->didKey();
		$identity = $this->identityWithHead('keeper', [$user, $this->rotation->didKey()]);

		$first = Mnemonic::keyFromPhrase($this->service->issueRecoveryKey($identity))->didKey();
		$this->assertSame([$user, $first, $this->rotation->didKey()], $this->lastOperation()['rotationKeys']);

		$second = Mnemonic::keyFromPhrase($this->service->issueRecoveryKey($this->stored[$identity->did]))->didKey();
		$this->assertSame([$user, $second, $this->rotation->didKey()], $this->lastOperation()['rotationKeys']);
	}

	/** Five keys are the most a DID lists: a phrase that would be a sixth is refused, and nothing is dropped for it. */
	public function testTheFiveKeyCapHolds(): void {
		$users = array_map(static fn (): string => PrivateKey::generate(Curve::K256)->didKey(), range(1, 4));
		$identity = $this->identityWithHead('full', [...$users, $this->rotation->didKey()]);
		$logged = count($this->logged);
		$this->identityRequest->expects($this->never())->method('setRecoveryPublic');

		try {
			$this->service->issueRecoveryKey($identity);
			$this->fail('a sixth key was listed');
		} catch (AtprotoException $e) {
			$this->assertStringContainsString('5 rotation keys already', $e->getMessage());
		}
		$this->assertCount($logged, $this->logged);

		$this->service->useCustomHandle($identity, 'full.example.org');
		$this->assertSame([...$users, $this->rotation->didKey()], $this->lastOperation()['rotationKeys']);
	}

	/**
	 * An operation built on a head the directory has moved past is carried
	 * onto the new head: a key the person added meanwhile stays.
	 */
	public function testAnOperationBuiltOnAnOlderHeadKeepsAKeyThePersonAddedMeanwhile(): void {
		$identity = $this->service->forActor(self::actor('meanwhile'));
		$this->assertNotNull($identity);
		$this->plc->method('submit')->willReturnCallback(function (): void {
			if (count($this->logged) === 2) {
				throw new AtprotoException('PLC directory could not be reached');
			}
		});
		$this->service->useCustomHandle($identity, 'meanwhile.example.org');
		$this->assertSame(0, $this->logged[1]['confirmed'], 'left for repair');
		$user = PrivateKey::generate(Curve::K256)->didKey();
		$theirs = PlcOperation::sign(PlcOperation::build([$user, $this->rotation->didKey()], $identity->signingPublic, $identity->handle, 'https://social.test', $this->logged[0]['cid']), $this->rotation);
		$this->plc->method('auditLog')->willReturn([
			['cid' => $this->logged[0]['cid'], 'operation' => $this->logged[0]['operation'], 'nullified' => false],
			['cid' => PlcOperation::cid($theirs)->toString(), 'operation' => $theirs, 'nullified' => false],
		]);

		$this->service->repair();

		$sent = $this->logged[1]['operation'];
		$this->assertSame(1, $this->logged[1]['confirmed']);
		$this->assertSame(PlcOperation::cid($theirs)->toString(), $sent['prev']);
		$this->assertSame([$user, $this->rotation->didKey()], $sent['rotationKeys']);
		$this->assertSame(['at://meanwhile.example.org'], $sent['alsoKnownAs']);
	}

	public function testHandingOverPointsTheDidAtTheOtherPdsAndKeepsTheRecoveryKeyFirst(): void {
		$identity = $this->service->forActor(self::actor('mover'));
		$this->assertNotNull($identity);
		$recovery = PrivateKey::generate(Curve::K256)->didKey();
		$withRecovery = new Identity($identity->id, $identity->actorId, $identity->did, $identity->handle, $identity->sealedSigningKey, $identity->signingPublic, $recovery, Identity::STATE_ACTIVE, '', $identity->creation);
		$theirs = PrivateKey::generate(Curve::K256)->didKey();
		$signing = PrivateKey::generate(Curve::K256)->didKey();
		$this->plc->expects($this->once())->method('submit')->with($identity->did, $this->callback(fn (array $operation): bool => $operation['rotationKeys'] === [$recovery, $theirs]
			&& $operation['verificationMethods'] === ['atproto' => $signing]
			&& $operation['alsoKnownAs'] === ['at://mover.pds.example.com']
			&& $operation['services']['atproto_pds']['endpoint'] === 'https://pds.example.com'
			&& $operation['prev'] === $this->logged[0]['cid']
			&& PlcOperation::verify($operation, $this->rotation->publicKey())));
		$this->plcLog->expects($this->once())->method('markConfirmed');

		$this->service->handOver($withRecovery, [
			'rotationKeys' => [$theirs],
			'verificationMethods' => ['atproto' => $signing],
			'alsoKnownAs' => ['at://mover.pds.example.com'],
			'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => 'https://pds.example.com']],
		]);
	}

	public function testADidThatMovedHereBecomesTheAccountsAndRetiresTheOldOne(): void {
		$old = $this->service->forActor(self::actor('arriving'));
		$this->assertNotNull($old);
		$moved = 'did:plc:z72i7hdynmk6r22z27h6tvur';
		$key = PrivateKey::generate(Curve::K256);
		$operation = ['type' => 'plc_operation', 'rotationKeys' => [$this->rotation->didKey()], 'verificationMethods' => ['atproto' => $key->didKey()], 'alsoKnownAs' => ['at://arriving.social.test'], 'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => 'https://social.test']], 'prev' => 'bafyreiprev', 'sig' => 'c2ln'];
		$submitted = [];
		$this->plc->method('submit')->willReturnCallback(static function (string $did, array $op) use (&$submitted): void {
			$submitted[] = [$did, $op['type']];
		});
		$this->identityRequest->method('adoptDid')->willReturnCallback(function (int $id, string $did, string $sealed, string $public, string $from) use ($old): void {
			$this->stored[$did] = new Identity($id, $old->actorId, $did, $old->handle, $sealed, $public, '', Identity::STATE_ACTIVE, $from, $old->creation);
		});
		$this->repositories->expects($this->once())->method('delete')->with($old->did);
		$announced = [];
		$this->events->method('account')->willReturnCallback(static function (string $did, bool $active) use (&$announced): int {
			$announced[] = [$did, $active];

			return 1;
		});

		$adopted = $this->service->adopt($old, $moved, $key, 'https://pds.example.com', $operation);

		$this->assertSame([[$moved, 'plc_operation'], [$old->did, 'plc_tombstone']], $submitted, 'the directory takes the move first, then the old DID is retired');
		$this->assertSame($moved, $adopted->did);
		$this->assertSame('arriving.social.test', $adopted->handle, 'the handle stays');
		$this->assertSame($key->didKey(), $adopted->signingPublic);
		$this->assertSame($key->secret(), $this->service->signingKey($adopted)->secret(), 'signed with the key made for it here');
		$this->assertSame([[$old->did, false], [$moved, true]], $announced, 'the old DID is announced gone, the moved one active');
	}

	public function testADidTheOtherSidePointedHereIsTakenWithNothingSent(): void {
		$old = $this->service->forActor(self::actor('bridged'));
		$this->assertNotNull($old);
		$moved = 'did:plc:3guzzweuqraryl3rdkimjamk';
		$key = PrivateKey::generate(Curve::K256);
		$submitted = [];
		$this->plc->method('submit')->willReturnCallback(static function (string $did, array $op) use (&$submitted): void {
			$submitted[] = [$did, $op['type']];
		});
		$this->identityRequest->method('adoptDid')->willReturnCallback(function (int $id, string $did, string $sealed, string $public, string $from) use ($old): void {
			$this->stored[$did] = new Identity($id, $old->actorId, $did, $old->handle, $sealed, $public, '', Identity::STATE_ACTIVE, $from, $old->creation);
		});

		$received = $this->service->receive($old, $moved, $key, 'https://atproto.brid.gy');

		$this->assertSame([[$old->did, 'plc_tombstone']], $submitted, 'only the old DID is retired');
		$this->assertSame([$moved, 'https://atproto.brid.gy'], [$received->did, $received->movedFromPds]);
	}

	public function testAHandOverTheDirectoryRefusedIsNotSentAgainByRepair(): void {
		$identity = $this->service->forActor(self::actor('stayer'));
		$this->assertNotNull($identity);
		$this->plc->method('submit')->willThrowException(new \OCA\Social\Exceptions\AtprotoException('refused'));
		$this->plcLog->expects($this->once())->method('remove')->with(2);

		$this->expectException(\OCA\Social\Exceptions\AtprotoException::class);
		$this->service->handOver($identity, [
			'rotationKeys' => [PrivateKey::generate(Curve::K256)->didKey()],
			'verificationMethods' => ['atproto' => PrivateKey::generate(Curve::K256)->didKey()],
			'alsoKnownAs' => ['at://stayer.pds.example.com'],
			'services' => ['atproto_pds' => ['endpoint' => 'https://pds.example.com']],
		]);
	}

	/**
	 * A handover whose answer never came is not in the log, but the
	 * directory may have taken it: once it points the DID at the other
	 * server, the operation is taken into the log from the directory's.
	 */
	public function testAHandOverTheDirectoryTookWithoutAnAnswerIsTakenIntoTheLog(): void {
		$identity = $this->service->forActor(self::actor('leaver'));
		$this->assertNotNull($identity);
		$sent = [];
		$this->plc->method('submit')->willReturnCallback(static function (string $did, array $operation) use (&$sent): never {
			$sent[] = $operation;
			throw new AtprotoException('PLC directory could not be reached: timeout');
		});
		try {
			$this->service->handOver($identity, [
				'rotationKeys' => [PrivateKey::generate(Curve::K256)->didKey()],
				'verificationMethods' => ['atproto' => PrivateKey::generate(Curve::K256)->didKey()],
				'alsoKnownAs' => ['at://leaver.pds.example.com'],
				'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => 'https://pds.example.com']],
			]);
			$this->fail('handed over');
		} catch (AtprotoException) {
		}
		$this->assertCount(1, $this->rows(static fn (array $row): bool => true), 'not left for repair() to send again');
		$genesis = $this->logged[0];
		$handOver = PlcOperation::cid($sent[0])->toString();
		$this->plc->method('auditLog')->willReturn([
			['cid' => $genesis['cid'], 'operation' => $genesis['operation'], 'nullified' => false],
			['cid' => $handOver, 'operation' => $sent[0], 'nullified' => false],
		]);
		$this->plc->method('data')->willReturn(['services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => 'https://pds.example.com']]]);

		$this->assertFalse($this->service->pointsAt($identity->did, 'https://other.example.com'));
		$this->assertCount(1, $this->rows(static fn (array $row): bool => true), 'the log is left alone for another server');
		$this->assertTrue($this->service->pointsAt($identity->did, 'https://pds.example.com/'));

		$this->assertSame($handOver, $this->lastCid($identity->did, true), 'the log\'s confirmed head is the directory\'s');
	}

	public function testSwitchingOffAnnouncesTheAccountInactiveAndOnAgainActive(): void {
		$identity = $this->service->forActor(self::actor('erin'));
		$this->assertNotNull($identity);
		$this->plc->expects($this->never())->method('submit');
		$this->repositories->expects($this->never())->method('delete');
		$head = Commit::sign($identity->did, (new Mst([]))->build()->root, Tid::next(), PrivateKey::generate(Curve::K256));
		$this->repositories->method('headCommit')->willReturn($head);
		$states = [];
		$this->identityRequest->method('setState')->willReturnCallback(static function (string $did, string $state) use (&$states): void {
			$states[] = $state;
		});
		$announced = [];
		$this->events->method('account')->willReturnCallback(static function (string $did, bool $active, string $status = '') use (&$announced): int {
			$announced[] = [$active, $status];

			return 1;
		});
		$this->events->method('identity')->willReturnCallback(static function (string $did, string $handle) use (&$announced): int {
			$announced[] = ['identity', $handle];

			return 1;
		});
		$this->events->method('sync')->willReturnCallback(static function (string $did, Commit $commit) use (&$announced): int {
			$announced[] = ['sync', $commit->rev];

			return 1;
		});
		$announced = [];

		$this->service->deactivate($identity);
		$this->service->deactivate($identity);
		$off = new Identity($identity->id, $identity->actorId, $identity->did, $identity->handle, $identity->sealedSigningKey, $identity->signingPublic, '', Identity::STATE_DEACTIVATED, '', $identity->creation);
		$this->service->deactivate($off);
		$this->service->activate($off);
		$this->service->activate($identity);

		$this->assertSame([Identity::STATE_DEACTIVATED, Identity::STATE_DEACTIVATED, Identity::STATE_ACTIVE], $states, 'the DID keeps its state row; only a change is written');
		$this->assertSame([[false, 'deactivated'], [false, 'deactivated'], [true, ''], ['identity', 'erin.social.test'], ['sync', $head->rev]], $announced, 'on again, the handle is resolved again and the repository taken as it is');
		$this->assertSame(['did' => $off->did, 'handle' => $off->handle, 'signing_key' => $off->signingPublic, 'state' => 'deactivated', 'active' => false, 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $off->creation)], $off->toArray());
	}

	public function testAMovedAwayOrTombstonedIdentityIsNeitherSwitchedOffNorOn(): void {
		$this->identityRequest->expects($this->never())->method('setState');
		$this->events->expects($this->never())->method('account');
		foreach ([Identity::STATE_MOVED_AWAY, Identity::STATE_TOMBSTONED] as $state) {
			$identity = new Identity(1, 'https://social.test/@gone', 'did:plc:gone', 'gone.social.test', '', '', '', $state, '', 0);
			$this->service->deactivate($identity);
			$this->service->activate($identity);
		}
	}

	/**
	 * Acting as the person on Bluesky takes an identity that is on there;
	 * one switched off is kept, never made again, and not acted as.
	 */
	public function testOnlyAnIdentityThatIsOnIsActedAsAndASwitchedOffOneIsNotMadeAgain(): void {
		$actor = self::actor('fay');
		$this->assertNull($this->service->activeForActor($actor), 'none is made');
		$this->identityRequest->expects($this->never())->method('create');
		$this->plc->expects($this->never())->method('submit');

		$this->byActor[$actor->getId()] = new Identity(7, $actor->getId(), 'did:plc:fay', 'fay.social.test', '', '', '', Identity::STATE_DEACTIVATED, '', 0);
		$this->assertNull($this->service->activeForActor($actor));
		$this->assertSame(Identity::STATE_DEACTIVATED, $this->service->forActor($actor)?->state, 'not made again, nor switched on');

		$this->byActor[$actor->getId()] = new Identity(7, $actor->getId(), 'did:plc:fay', 'fay.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->assertSame('did:plc:fay', $this->service->activeForActor($actor)?->did);
	}

	public function testTheSwitchedOffAreCounted(): void {
		$this->identityRequest->method('countInState')->willReturnCallback(static fn (string $state): int => $state === Identity::STATE_DEACTIVATED ? 3 : 0);

		$this->assertSame(3, $this->service->countDeactivated());
	}

	public function testATombstoneEndsEverything(): void {
		$identity = $this->service->forActor(self::actor('dave'));
		$this->assertNotNull($identity);
		$this->plc->expects($this->once())->method('submit')->with($identity->did, $this->callback(fn (array $op): bool => $op['type'] === 'plc_tombstone' && $op['prev'] === $this->logged[0]['cid'] && PlcOperation::verify($op, $this->rotation->publicKey())));
		$this->identityRequest->expects($this->once())->method('setState')->with($identity->did, Identity::STATE_TOMBSTONED);
		$this->repositories->expects($this->once())->method('delete')->with($identity->did);
		$this->blobs->expects($this->once())->method('deleteByDid')->with($identity->did);
		$this->events->expects($this->once())->method('account')->with($identity->did, false, 'deleted');

		$this->service->tombstone($identity);
	}

	public function testNothingIsMadeForARemoteOrMovedActor(): void {
		$remote = self::actor('eve');
		$remote->setLocal(false);
		$this->assertNull($this->service->forActor($remote));

		$moved = self::actor('fay');
		$moved->setMovedTo('https://elsewhere.example/@fay');
		$this->assertNull($this->service->forActor($moved));
	}

	/** A signing key the instance secret no longer opens is said so, in words an administrator can act on. */
	public function testASigningKeyThatDoesNotOpenAsksForTheReseal(): void {
		$identity = new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', 'sealed with another secret', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0);

		$this->expectException(AtprotoKeysSealedException::class);
		$this->expectExceptionMessage('occ social:atproto:keys:reseal');
		$this->service->signingKey($identity);
	}

	public function testTheInstanceDocumentNamesTheServiceKeyAndThePds(): void {
		$document = $this->service->instanceDocument();

		$this->assertSame('did:web:social.test', $document['id']);
		$this->assertSame(['at://social.test'], $document['alsoKnownAs'], 'a handle, or the reference resolver will not take it');
		$this->assertSame('did:web:social.test#atproto', $document['verificationMethod'][0]['id']);
		$this->assertInstanceOf(PublicKey::class, PublicKey::fromDidKey('did:key:' . $document['verificationMethod'][0]['publicKeyMultibase']));
		$this->assertSame('https://social.test', $document['service'][0]['serviceEndpoint']);
	}

	/** @return array<int, array> the rows not removed that $keep takes, as the request returns them */
	private function rows(callable $keep): array {
		return array_values(array_filter($this->logged, static fn (array $row): bool => !($row['removed'] ?? false) && $keep($row)));
	}

	private function lastCid(string $did, bool $confirmedOnly): string {
		foreach (array_reverse($this->logged) as $row) {
			if ($row['did'] === $did && $row['dead'] === 0 && !($row['removed'] ?? false) && (!$confirmedOnly || $row['confirmed'] !== 0)) {
				return $row['cid'];
			}
		}

		return '';
	}

	private static function actor(string $username): Person {
		$actor = new Person();
		$actor->setId('https://social.test/@' . $username);
		$actor->setPreferredUsername($username);
		$actor->setLocal(true);

		return $actor;
	}
}
