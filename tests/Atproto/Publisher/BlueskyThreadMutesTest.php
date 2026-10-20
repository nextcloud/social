<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\BlueskyThreadMutes;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BlueskyThreadMutesTest extends TestCase {
	private function service(?AccountService $accounts = null, ?StreamRequest $streams = null, ?ConversationsRequest $conversations = null, ?LocalRecordResolver $records = null): BlueskyThreadMutes {
		return new BlueskyThreadMutes(
			$accounts ?? $this->createStub(AccountService::class),
			$streams ?? $this->createStub(StreamRequest::class),
			$conversations ?? $this->createStub(ConversationsRequest::class),
			$this->createStub(LoggerInterface::class),
			$records ?? $this->createStub(LocalRecordResolver::class),
		);
	}

	#[DataProvider('muteMethods')]
	public function testAnAppThreadMuteResolvesTheRecordBeforeUpdatingLocalState(string $method, bool $muted): void {
		$uri = 'at://did:plc:bob/app.bsky.feed.post/root';
		$postId = 'https://bsky.app/profile/did:plc:bob/post/root';
		$rootId = 'https://social.example/@bob/status/root';
		$post = $this->createStub(Stream::class);
		$post->method('getId')->willReturn($postId);
		$records = $this->createMock(LocalRecordResolver::class);
		$records->expects($this->once())->method('postId')->with($uri)->willReturn($postId);
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->once())->method('getStreamById')->with($postId, false, ACore::FORMAT_LOCAL)->willReturn($post);
		$viewer = new Person();
		$viewer->setId('https://social.example/@alice');
		$accounts = $this->createMock(AccountService::class);
		$accounts->expects($this->once())->method('getActorFromUserId')->with('alice')->willReturn($viewer);
		$conversations = $this->createMock(ConversationsRequest::class);
		$conversations->expects($this->once())->method('rootOf')->with($postId)->willReturn($rootId);
		$conversations->expects($this->once())->method('setMuted')->with($viewer->getId(), $rootId, $muted);

		$this->service($accounts, $streams, $conversations, $records)->fromApp(
			new ClientSession('alice', new Identity(1, $viewer->getId(), 'did:plc:alice', 'alice.example', '', '', '', Identity::STATE_ACTIVE, '', 0), 'jti'),
			$method,
			['root' => $uri],
		);
	}

	public function testAnAppThreadMuteResolvesALocalMirroredPost(): void {
		$uri = 'at://did:plc:local/app.bsky.feed.post/root';
		$postId = 'https://social.example/@alice/status/42';
		$rootId = 'https://social.example/@alice/status/40';
		$post = $this->createStub(Stream::class);
		$post->method('getId')->willReturn($postId);
		$records = $this->createMock(LocalRecordResolver::class);
		$records->expects($this->once())->method('postId')->with($uri)->willReturn($postId);
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->once())->method('getStreamById')->with($postId, false, ACore::FORMAT_LOCAL)->willReturn($post);
		$viewer = new Person();
		$viewer->setId('https://social.example/@alice');
		$accounts = $this->createMock(AccountService::class);
		$accounts->expects($this->once())->method('getActorFromUserId')->with('alice')->willReturn($viewer);
		$conversations = $this->createMock(ConversationsRequest::class);
		$conversations->expects($this->once())->method('rootOf')->with($postId)->willReturn($rootId);
		$conversations->expects($this->once())->method('setMuted')->with($viewer->getId(), $rootId, true);

		$this->service($accounts, $streams, $conversations, $records)->fromApp(
			new ClientSession('alice', new Identity(1, $viewer->getId(), 'did:plc:local', 'alice.example', '', '', '', Identity::STATE_ACTIVE, '', 0), 'jti'),
			BlueskyThreadMutes::MUTE,
			['root' => $uri],
		);
	}

	public function testAnUncachedThreadRootDoesNotChangeLocalState(): void {
		$records = $this->createStub(LocalRecordResolver::class);
		$records->method('postId')->willReturn('https://bsky.app/profile/did:plc:bob/post/missing');
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->once())->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$conversations = $this->createMock(ConversationsRequest::class);
		$conversations->expects($this->never())->method('setMuted');

		$this->service(streams: $streams, conversations: $conversations, records: $records)->fromApp(
			new ClientSession('alice', new Identity(1, '', 'did:plc:alice', 'alice.example', '', '', '', Identity::STATE_ACTIVE, '', 0), 'jti'),
			BlueskyThreadMutes::MUTE,
			['root' => 'at://did:plc:bob/app.bsky.feed.post/missing'],
		);
	}

	/** @return array<string, array{string, bool}> */
	public static function muteMethods(): array {
		return [
			'mute' => [BlueskyThreadMutes::MUTE, true],
			'unmute' => [BlueskyThreadMutes::UNMUTE, false],
		];
	}
}
