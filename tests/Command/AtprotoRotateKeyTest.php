<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Command\AtprotoRotateKey;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ social:atproto:rotate-key`: every identity the new key has to be
 * in charge of gets its operation — a pending one too, whose operation
 * waits behind its genesis — and one that is gone does not.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoRotateKeyTest extends TestCase {
	public function testAPendingIdentityIsReRegisteredAndAGoneOneIsNot(): void {
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$new = PrivateKey::generate(Curve::K256);
		$old = PrivateKey::generate(Curve::K256);
		$keys = $this->createMock(InstanceKeyService::class);
		$keys->method('rotate')->willReturn([$new, $old]);
		$identities = $this->createMock(IdentityService::class);
		$rows = [
			new Identity(1, 'https://social.test/@active', 'did:plc:active', 'active.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0),
			new Identity(2, 'https://social.test/@pending', 'did:plc:pending', 'pending.social.test', '', '', '', Identity::STATE_PENDING, '', 0),
			new Identity(3, 'https://social.test/@off', 'did:plc:off', 'off.social.test', '', '', '', Identity::STATE_DEACTIVATED, '', 0),
			new Identity(4, 'https://social.test/@gone', 'did:plc:gone', 'gone.social.test', '', '', '', Identity::STATE_TOMBSTONED, '', 0),
			new Identity(5, 'https://social.test/@moved', 'did:plc:moved', 'moved.social.test', '', '', '', Identity::STATE_MOVED_AWAY, '', 0),
		];
		$identities->method('count')->willReturn(count($rows));
		$identities->method('getAll')->willReturnCallback(static fn (int $limit, int $offset): array => array_slice($rows, $offset, $limit));
		$registered = [];
		$identities->method('rotateInstanceKey')->willReturnCallback(static function (Identity $identity, PrivateKey $with, PrivateKey $signer) use (&$registered, $new, $old): void {
			$registered[] = [$identity->did, $with === $new, $signer === $old];
		});

		$tester = new CommandTester(new AtprotoRotateKey($config, $keys, $identities));
		$this->assertSame(0, $tester->execute(['--force' => true]));

		$this->assertSame([['did:plc:active', true, true], ['did:plc:pending', true, true]], $registered);
		$this->assertStringContainsString('2 identities re-registered, 0 failed', $tester->getDisplay());
	}
}
