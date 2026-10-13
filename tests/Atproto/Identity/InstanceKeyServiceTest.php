<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Model\InstanceKey;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Security\PrivateKeyCipher;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The instance's rotation and service keys (§16.1, §16.3): made on first
 * need and stored only sealed, the same key on every later call, a
 * rotation that keeps the old key retired for the PLC's recovery window
 * and drops it after, and a seal that no longer opens is an error rather
 * than a silently new key (which would lock every account out of its own
 * DID).
 */
#[AllowMockObjectsWithoutExpectations]
class InstanceKeyServiceTest extends TestCase {
	/** @var array<int, InstanceKey> */
	private array $rows = [];
	private int $now = 1760000000;
	private string $secret = 'instance secret';

	private function service(): InstanceKeyService {
		$request = $this->createMock(AtprotoIdentityRequest::class);
		$request->method('createInstanceKey')->willReturnCallback(function (string $kind, string $sealed, string $didKey): int {
			$id = count($this->rows) + 1;
			$this->rows[$id] = new InstanceKey($id, $kind, $sealed, $didKey, $this->now, 0);

			return $id;
		});
		$request->method('getInstanceKeys')->willReturnCallback(fn (string $kind): array => array_values(array_reverse(array_filter($this->rows, static fn (InstanceKey $key): bool => $key->kind === $kind))));
		$request->method('retireInstanceKey')->willReturnCallback(function (int $id): void {
			$key = $this->rows[$id];
			$this->rows[$id] = new InstanceKey($id, $key->kind, $key->sealedPrivateKey, $key->publicDidKey, $key->creation, $this->now);
		});
		$request->method('deleteInstanceKey')->willReturnCallback(function (int $id): void {
			unset($this->rows[$id]);
		});
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn (string $plain): string => base64_encode($this->secret . '|' . $plain));
		$crypto->method('decrypt')->willReturnCallback(function (string $sealed): string {
			[$secret, $plain] = explode('|', (string)base64_decode($sealed), 2) + ['', ''];
			if ($secret !== $this->secret) {
				throw new RuntimeException('HMAC does not match');
			}

			return $plain;
		});

		return new InstanceKeyService($request, new PrivateKeyCipher($crypto, new NullLogger()));
	}

	public function testAKeyIsMadeOnceStoredSealedAndOpenedAgainLater(): void {
		$rotation = $this->service()->rotationKey();
		$service = $this->service()->serviceKey();

		$this->assertCount(2, $this->rows);
		$this->assertNotSame($rotation->didKey(), $service->didKey(), 'two keys for two jobs');
		foreach ($this->rows as $row) {
			$this->assertStringNotContainsString($row->kind === InstanceKey::KIND_ROTATION ? $rotation->secret() : $service->secret(), $row->sealedPrivateKey, 'never stored in the clear');
		}
		$this->assertSame($rotation->didKey(), $this->service()->rotationKey()->didKey(), 'a later request opens the stored one');
		$this->assertSame($rotation->didKey(), $this->rows[1]->publicDidKey);
		$this->assertCount(2, $this->rows);
		$this->assertSame($this->now, $this->service()->rotationKeyAge());
	}

	public function testARotationKeepsTheOldKeyForTheWindowThenDropsIt(): void {
		$keys = $this->service();
		$first = $keys->rotationKey();
		$this->now += 3600;
		[$new, $old] = $keys->rotate();

		$this->assertSame($first->didKey(), $old->didKey(), 'the old key signs the handover');
		$this->assertNotSame($first->didKey(), $new->didKey());
		$this->assertSame($new->didKey(), $this->service()->rotationKey()->didKey(), 'the new one is in use from now on');
		$this->assertSame($this->now, $this->service()->rotationKeyAge());

		$this->assertSame(0, $keys->pruneRetired($this->now + InstanceKeyService::RETIRED_KEY_LIFETIME), 'kept for the whole window');
		$this->assertSame(1, $keys->pruneRetired($this->now + InstanceKeyService::RETIRED_KEY_LIFETIME + 1));
		$this->assertSame([$new->didKey()], array_values(array_map(static fn (InstanceKey $key): string => $key->publicDidKey, $this->rows)));
	}

	public function testTheRetiredKeysAreListedAfterTheOneInUse(): void {
		$keys = $this->service();
		$first = $keys->rotationKey();
		[$second] = $keys->rotate();
		[$third] = $keys->rotate();

		$this->assertSame([$third->didKey(), $second->didKey(), $first->didKey()], array_map(static fn ($key): string => $key->didKey(), $this->service()->rotationKeys()));
		$keys->pruneRetired($this->now + InstanceKeyService::RETIRED_KEY_LIFETIME + 1);
		$this->assertSame([$third->didKey()], array_map(static fn ($key): string => $key->didKey(), $this->service()->rotationKeys()));
	}

	public function testASealThatNoLongerOpensIsAnErrorNotANewKey(): void {
		$this->service()->rotationKey();
		$this->secret = 'another instance secret';

		try {
			$this->service()->rotationKey();
			$this->fail('a key was handed out');
		} catch (AtprotoException $e) {
			$this->assertStringContainsString('instance secret changed', $e->getMessage());
		}
		$this->assertCount(1, $this->rows, 'no second key was made behind the directory\'s back');
	}
}
