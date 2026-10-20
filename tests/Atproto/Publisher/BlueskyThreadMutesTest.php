<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\BlueskyThreadMutes;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BlueskyThreadMutesTest extends TestCase {
	#[DataProvider('muteMethods')]
	public function testASocialThreadMuteUsesTheBlueskyRoot(string $method, bool $muted): void {
		$viewer = new Person();
		$viewer->setId('https://social.example/@alice');
		$rootId = 'https://bsky.app/profile/did:plc:bob/post/root';
		$conversations = $this->createStub(ConversationsRequest::class);
		$identity = new Identity(1, $viewer->getId(), 'did:plc:alice', 'alice.example', '', '', '', Identity::STATE_ACTIVE, '', 0);
		$key = new PrivateKey(Curve::K256, str_repeat("\0", 31) . "\1");
		$identities = $this->createMock(IdentityService::class);
		$identities->expects($this->once())->method('activeForActor')->with($viewer)->willReturn($identity);
		$identities->expects($this->once())->method('signingKey')->with($identity)->willReturn($key);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->once())->method('procedureAs')->with(
			$identity->did,
			$key,
			$method,
			['root' => 'at://did:plc:bob/app.bsky.feed.post/root']
		)->willReturn([]);

		$service = new BlueskyThreadMutes(
			$appView,
			$identities,
			$this->createStub(AccountService::class),
			$this->createStub(StreamRequest::class),
			$conversations,
			$this->createStub(LoggerInterface::class),
		);
		$service->fromSocial($viewer, $rootId, $muted);
	}

	/** @return array<string, array{string, bool}> */
	public static function muteMethods(): array {
		return [
			'mute' => [BlueskyThreadMutes::MUTE, true],
			'unmute' => [BlueskyThreadMutes::UNMUTE, false],
		];
	}
}
