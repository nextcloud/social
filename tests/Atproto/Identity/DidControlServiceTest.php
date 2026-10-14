<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use InvalidArgumentException;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\DidControlService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Identity\PlcOperation;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who controls a DID (R3), from the directory's audit log of real signed
 * operations: the rotation keys by what they are to the person, who
 * signed the latest operation, and for 72 hours after it, until when and
 * by which higher key it can still be undone.
 */
#[AllowMockObjectsWithoutExpectations]
class DidControlServiceTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const T0 = 1760000000;

	private PrivateKey $server;
	private PrivateKey $retired;
	private PrivateKey $recovery;
	private PrivateKey $stranger;
	private PlcClient&MockObject $plc;
	/** @var list<array> the directory's audit log */
	private array $audit = [];
	private int $now = self::T0;
	private string $confirmed = 'bafyhead';
	/** @var array<string, mixed> */
	private array $cached = [];

	protected function setUp(): void {
		$this->server = PrivateKey::generate(Curve::K256);
		$this->retired = PrivateKey::generate(Curve::K256);
		$this->recovery = PrivateKey::generate(Curve::K256);
		$this->stranger = PrivateKey::generate(Curve::K256);
		$this->plc = $this->createMock(PlcClient::class);
		$this->plc->method('auditLog')->willReturnCallback(fn (): array => $this->audit);
	}

	private function service(): DidControlService {
		$plcLog = $this->createMock(AtprotoPlcLogRequest::class);
		$plcLog->method('confirmedCid')->willReturnCallback(fn (): string => $this->confirmed);
		$instanceKeys = $this->createMock(InstanceKeyService::class);
		$instanceKeys->method('rotationDidKeys')->willReturnCallback(fn (): array => [$this->server->didKey() => false, $this->retired->didKey() => true]);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cached[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->cached[$key] = $value;

			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new DidControlService($this->plc, $plcLog, $instanceKeys, $cacheFactory, $time);
	}

	private static function identity(string $recovery = ''): Identity {
		return new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', 'sealed', 'did:key:z', $recovery, Identity::STATE_ACTIVE, '', 0);
	}

	/**
	 * Appends an operation to the directory's log, signed by $signer, after the last one.
	 *
	 * @param list<PrivateKey> $rotation
	 */
	private function operation(array $rotation, PrivateKey $signer, int $at, bool $nullified = false): void {
		$prev = $this->audit === [] ? null : $this->audit[count($this->audit) - 1]['cid'];
		foreach (array_reverse($this->audit) as $entry) {
			if (!$entry['nullified']) {
				$prev = $entry['cid'];
				break;
			}
		}
		$operation = PlcOperation::sign(PlcOperation::build(
			array_map(static fn (PrivateKey $key): string => $key->didKey(), $rotation), 'did:key:zQ3shsigning', 'alice.social.test', 'https://social.test', $prev,
		), $signer);
		$this->audit[] = ['did' => self::DID, 'operation' => $operation, 'cid' => PlcOperation::cid($operation)->toString(), 'nullified' => $nullified, 'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z', $at)];
	}

	public function testTheKeysAreNamedByWhatTheyAreToThePerson(): void {
		$this->operation([$this->server], $this->server, self::T0 - 86400 * 10);
		$this->operation([$this->server, $this->recovery, $this->retired, $this->stranger], $this->server, self::T0 - 3600);

		$control = $this->service()->forIdentity(self::identity($this->recovery->didKey()));

		$this->assertSame('active', $control['state']);
		$this->assertSame([
			['key' => $this->server->didKey(), 'role' => 'server'],
			['key' => $this->recovery->didKey(), 'role' => 'recovery'],
			['key' => $this->retired->didKey(), 'role' => 'server-retired'],
			['key' => $this->stranger->didKey(), 'role' => 'unknown'],
		], $control['rotation_keys']);
		$this->assertSame(['key' => $this->server->didKey(), 'role' => 'server'], $control['last_operation']['signed_by']);
		$this->assertSame(self::T0 - 3600, $control['last_operation']['created_at']);
		$this->assertNull($control['undo'], 'nothing ranks above the key that signed it');
	}

	/** An operation signed with the phrase can be undone by this server's key for 72 hours, and not after. */
	public function testAnOperationSignedWithThePhraseCanBeUndoneByTheServerForSeventyTwoHours(): void {
		$this->operation([$this->server], $this->server, self::T0 - 86400 * 10);
		$this->operation([$this->server, $this->recovery], $this->server, self::T0 - 86400 * 5);
		$signed = self::T0 - 3600;
		$this->operation([$this->recovery, $this->stranger], $this->recovery, $signed);

		$control = $this->service()->forIdentity(self::identity($this->recovery->didKey()));

		$this->assertSame(['key' => $this->recovery->didKey(), 'role' => 'recovery'], $control['last_operation']['signed_by']);
		$this->assertSame(['until' => $signed + 72 * 3600, 'by' => [['key' => $this->server->didKey(), 'role' => 'server']]], $control['undo']);
		$this->assertSame(['recovery', 'unknown'], array_column($control['rotation_keys'], 'role'));

		$this->now = $signed + 72 * 3600;
		$this->cached = [];
		$this->assertNull($this->service()->forIdentity(self::identity($this->recovery->didKey()))['undo']);
	}

	/** A nullified operation is not the latest: the one it replaced is. */
	public function testANullifiedOperationIsLeftOut(): void {
		$this->operation([$this->server, $this->recovery], $this->server, self::T0 - 86400);
		$this->operation([$this->stranger], $this->recovery, self::T0 - 3600, true);

		$control = $this->service()->forIdentity(self::identity($this->recovery->didKey()));

		$this->assertSame(['server', 'recovery'], array_column($control['rotation_keys'], 'role'));
	}

	public function testATombstonedDidHasNoKeys(): void {
		$this->operation([$this->server], $this->server, self::T0 - 86400);
		$tombstone = PlcOperation::sign(PlcOperation::tombstone($this->audit[0]['cid']), $this->server);
		$this->audit[] = ['did' => self::DID, 'operation' => $tombstone, 'cid' => PlcOperation::cid($tombstone)->toString(), 'nullified' => false, 'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z', self::T0 - 60)];

		$control = $this->service()->forIdentity(self::identity());

		$this->assertSame('tombstoned', $control['state']);
		$this->assertSame([], $control['rotation_keys']);
	}

	/** The directory is asked once a minute, and again at once when this server confirmed an operation. */
	public function testTheDirectoryAnswerIsKeptUntilThisServerConfirmsAnOperation(): void {
		$this->operation([$this->server], $this->server, self::T0 - 86400);
		$this->plc->expects($this->exactly(2))->method('auditLog');

		$this->service()->forIdentity(self::identity());
		$this->service()->forIdentity(self::identity());
		$this->confirmed = 'bafynewhead';
		$this->service()->forIdentity(self::identity());
	}

	public function testADidTheDirectoryDoesNotKnowIsSaidSo(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service()->forIdentity(self::identity());
	}
}
