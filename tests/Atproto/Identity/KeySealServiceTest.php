<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Identity;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\KeySealService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\InstanceKey;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Security\PrivateKeyCipher;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The instance secret changes: every key sealed under the old one is
 * counted as not opening, and the re-sealing opens each with the old
 * secret and stores it under the new one — the same keys, so the DIDs
 * stay the instance's and the accounts'. A key sealed with neither
 * secret is reported, not replaced.
 */
#[AllowMockObjectsWithoutExpectations]
class KeySealServiceTest extends TestCase {
	/** @var array<int, InstanceKey> */
	private array $instanceKeys = [];
	/** @var array<string, Identity> */
	private array $identities = [];
	private string $secret = 'first secret';
	private AtprotoIdentityRequest $request;
	private PrivateKeyCipher $cipher;

	protected function setUp(): void {
		$this->request = $this->createMock(AtprotoIdentityRequest::class);
		$this->request->method('createInstanceKey')->willReturnCallback(function (string $kind, string $sealed, string $didKey): int {
			$id = count($this->instanceKeys) + 1;
			$this->instanceKeys[$id] = new InstanceKey($id, $kind, $sealed, $didKey, 1, 0);

			return $id;
		});
		$this->request->method('getInstanceKeys')->willReturnCallback(fn (string $kind): array => array_values(array_filter($this->instanceKeys, static fn (InstanceKey $key): bool => $key->kind === $kind)));
		$this->request->method('retireInstanceKey')->willReturnCallback(function (int $id): void {
			$key = $this->instanceKeys[$id];
			$this->instanceKeys[$id] = new InstanceKey($id, $key->kind, $key->sealedPrivateKey, $key->publicDidKey, $key->creation, 2);
		});
		$this->request->method('resealInstanceKey')->willReturnCallback(function (int $id, string $sealed): void {
			$key = $this->instanceKeys[$id];
			$this->instanceKeys[$id] = new InstanceKey($id, $key->kind, $sealed, $key->publicDidKey, $key->creation, $key->retired);
		});
		$this->request->method('getAll')->willReturnCallback(fn (int $limit, int $offset): array => array_slice(array_values($this->identities), $offset, $limit));
		$this->request->method('setSigningKey')->willReturnCallback(function (string $did, string $sealed, string $public): void {
			$was = $this->identities[$did];
			$this->identities[$did] = new Identity($was->id, $was->actorId, $did, $was->handle, $sealed, $public, $was->recoveryPublic, $was->state, $was->movedFromPds, $was->creation);
		});
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn (string $plain, string $password = ''): string => base64_encode(($password === '' ? $this->secret : $password) . '|' . $plain));
		$crypto->method('decrypt')->willReturnCallback(function (string $sealed, string $password = ''): string {
			[$with, $plain] = explode('|', (string)base64_decode($sealed), 2) + ['', ''];
			if ($with !== ($password === '' ? $this->secret : $password)) {
				throw new RuntimeException('HMAC does not match');
			}

			return $plain;
		});
		$this->cipher = new PrivateKeyCipher($crypto, new NullLogger());
	}

	private function identity(string $name, string $state, string $sealed = ''): Identity {
		$key = PrivateKey::generate(Curve::K256);

		return $this->identities['did:plc:' . $name] = new Identity(count($this->identities) + 1, 'https://social.test/@' . $name, 'did:plc:' . $name, $name . '.social.test', $sealed === '' ? $this->cipher->seal($key->secret()) : $sealed, $key->didKey(), '', $state, '', 0);
	}

	public function testEveryKeyIsOpenedWithTheOldSecretAndSealedWithTheNewOne(): void {
		$instance = new InstanceKeyService($this->request, $this->cipher);
		$rotation = $instance->rotationKey();
		[$rotated] = $instance->rotate();
		$service = $instance->serviceKey();
		$this->identity('alice', Identity::STATE_ACTIVE);
		$this->identity('bob', Identity::STATE_DEACTIVATED);
		$this->identity('carol', Identity::STATE_PENDING);
		$this->identity('gone', Identity::STATE_TOMBSTONED);
		$this->identity('moved', Identity::STATE_MOVED_AWAY);
		$seals = new KeySealService($this->request, $this->cipher);
		$this->assertSame(0, $seals->countSealed());

		$this->secret = 'second secret';
		$this->assertSame(6, $seals->countSealed(), 'three instance keys, the retired one included, and the three identities that still sign');
		$this->assertSame(['resealed' => 6, 'failed' => 0], $seals->reseal('first secret'));

		$this->assertSame(0, $seals->countSealed());
		$this->assertSame([$rotated->didKey(), $service->didKey()], [(new InstanceKeyService($this->request, $this->cipher))->rotationKey()->didKey(), (new InstanceKeyService($this->request, $this->cipher))->serviceKey()->didKey()], 'the same keys, opened under the new secret');
		$this->assertSame($rotation->didKey(), (new InstanceKeyService($this->request, $this->cipher))->rotationKeys()[1]->didKey(), 'the retired key still signs for the window');
		foreach (['alice', 'bob', 'carol'] as $name) {
			$identity = $this->identities['did:plc:' . $name];
			$this->assertSame($identity->signingPublic, (new PrivateKey(Curve::K256, $this->cipher->open($identity->sealedSigningKey)))->didKey(), $name);
		}
		foreach (['gone', 'moved'] as $name) {
			$this->assertFalse($this->cipher->opens($this->identities['did:plc:' . $name]->sealedSigningKey), $name . ' signs nothing any more and is left alone');
		}
		$this->assertSame(['resealed' => 0, 'failed' => 0], $seals->reseal('first secret'), 'run again, nothing to do');
	}

	public function testAKeyNeitherSecretOpensIsReportedNotReplaced(): void {
		$this->identity('alice', Identity::STATE_ACTIVE);
		$stranger = $this->identity('dave', Identity::STATE_ACTIVE, base64_encode('third secret|' . PrivateKey::generate(Curve::K256)->secret()));
		$seals = new KeySealService($this->request, $this->cipher);
		$this->secret = 'second secret';
		$this->request->expects($this->once())->method('setSigningKey')->with('did:plc:alice');

		$this->assertSame(['resealed' => 1, 'failed' => 1], $seals->reseal('first secret'));
		$this->assertSame(1, $seals->countSealed());
		$this->assertSame($stranger->sealedSigningKey, $this->identities['did:plc:dave']->sealedSigningKey, 'kept as it is');
	}
}
