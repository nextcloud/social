<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Publisher;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\BlueskyThreadMutes;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Db\ConversationsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BlueskyThreadMutesTest extends TestCase {
	private function service(?AccountService $accounts = null, ?StreamRequest $streams = null, ?ConversationsRequest $conversations = null, ?LocalRecordResolver $records = null, ?PostRefs $postRefs = null): BlueskyThreadMutes {
		$records ??= $this->createStub(LocalRecordResolver::class);
		$records->method('postId')->willReturnCallback(BlueskyIds::postIdOfUri(...));

		return new BlueskyThreadMutes(
			$this->createStub(AppViewClient::class),
			$this->createStub(IdentityService::class),
			$accounts ?? $this->createStub(AccountService::class),
			$streams ?? $this->createStub(StreamRequest::class),
			$conversations ?? $this->createStub(ConversationsRequest::class),
			$this->createStub(LoggerInterface::class),
			$records,
			$postRefs ?? $this->createStub(PostRefs::class),
		);
	}

	#[DataProvider('muteMethods')]
	public function testAnAppThreadMuteUpdatesTheStoredLocalConversation(string $method, bool $muted): void {
		$viewer = new Person();
		$viewer->setId('https://social.example/@alice');
		$postId = 'https://bsky.app/profile/did:plc:bob/post/root';
		$localRootId = 'https://social.example/@bob/status/root';
		$post = $this->createStub(Stream::class);
		$post->method('getId')->willReturn($postId);
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->once())->method('getStreamById')->with($postId, false, \OCA\Social\Model\ActivityPub\ACore::FORMAT_LOCAL)->willReturn($post);
		$accounts = $this->createMock(AccountService::class);
		$accounts->expects($this->once())->method('getActorFromUserId')->with('alice')->willReturn($viewer);
		$conversations = $this->createMock(ConversationsRequest::class);
		$conversations->expects($this->once())->method('rootOf')->with($postId)->willReturn($localRootId);
		$conversations->expects($this->once())->method('setMuted')->with($viewer->getId(), $localRootId, $muted);
		$service = $this->service($accounts, $streams, $conversations);

		$service->fromApp(new ClientSession('alice', new Identity(1, $viewer->getId(), 'did:plc:alice', 'alice.example', '', '', '', Identity::STATE_ACTIVE, '', 0), 'jti'), $method, ['root' => 'at://did:plc:bob/app.bsky.feed.post/root']);
	}

	public function testAnUncachedThreadRootDoesNotChangeLocalConversationState(): void {
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->once())->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$conversations = $this->createMock(ConversationsRequest::class);
		$conversations->expects($this->never())->method('setMuted');
		$service = $this->service(streams: $streams, conversations: $conversations);

		$service->fromApp(new ClientSession('alice', new Identity(1, '', 'did:plc:alice', 'alice.example', '', '', '', Identity::STATE_ACTIVE, '', 0), 'jti'), BlueskyThreadMutes::MUTE, ['root' => 'at://did:plc:bob/app.bsky.feed.post/missing']);
	}

	public function testAnAppThreadMuteResolvesALocalMirroredPost(): void {
		$uri = 'at://did:plc:alice/app.bsky.feed.post/3k';
		$postId = 'https://social.example/@alice/status/42';
		$rootId = 'https://social.example/@alice/status/40';
		$post = $this->createStub(Stream::class);
		$post->method('getId')->willReturn($postId);
		$records = $this->createMock(LocalRecordResolver::class);
		$records->expects($this->once())->method('postId')->with($uri)->willReturn($postId);
		$streams = $this->createMock(StreamRequest::class);
		$streams->expects($this->once())->method('getStreamById')->with($postId, false, \OCA\Social\Model\ActivityPub\ACore::FORMAT_LOCAL)->willReturn($post);
		$viewer = new Person();
		$viewer->setId('https://social.example/@alice');
		$accounts = $this->createMock(AccountService::class);
		$accounts->expects($this->once())->method('getActorFromUserId')->with('alice')->willReturn($viewer);
		$conversations = $this->createMock(ConversationsRequest::class);
		$conversations->expects($this->once())->method('rootOf')->with($postId)->willReturn($rootId);
		$conversations->expects($this->once())->method('setMuted')->with($viewer->getId(), $rootId, true);

		$this->service($accounts, $streams, $conversations, $records)->fromApp(
			new ClientSession('alice', new Identity(1, $viewer->getId(), 'did:plc:alice', 'alice.example', '', '', '', Identity::STATE_ACTIVE, '', 0), 'jti'),
			BlueskyThreadMutes::MUTE,
			['root' => $uri],
		);
	}

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
			$this->createStub(LocalRecordResolver::class),
			$this->postRefs($rootId, 'at://did:plc:bob/app.bsky.feed.post/root'),
		);
		$service->fromSocial($viewer, $rootId, $muted);
	}

	#[DataProvider('muteMethods')]
	public function testASocialThreadMuteUsesTheLocalPostsBlueskyRecord(string $method, bool $muted): void {
		$viewer = new Person();
		$viewer->setId('https://social.example/@alice');
		$rootId = 'https://social.example/@alice/status/42';
		$rootUri = 'at://did:plc:alice/app.bsky.feed.post/3k';
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
			['root' => $rootUri]
		)->willReturn([]);

		$service = new BlueskyThreadMutes(
			$appView,
			$identities,
			$this->createStub(AccountService::class),
			$this->createStub(StreamRequest::class),
			$this->createStub(ConversationsRequest::class),
			$this->createStub(LoggerInterface::class),
			$this->createStub(LocalRecordResolver::class),
			$this->postRefs($rootId, $rootUri),
		);
		$service->fromSocial($viewer, $rootId, $muted);
	}

	public function testASocialThreadMuteWithoutABlueskyRecordIsIgnored(): void {
		$viewer = new Person();
		$viewer->setId('https://social.example/@alice');
		$identities = $this->createMock(IdentityService::class);
		$identities->expects($this->never())->method('activeForActor');
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->never())->method('procedureAs');

		$service = new BlueskyThreadMutes(
			$appView,
			$identities,
			$this->createStub(AccountService::class),
			$this->createStub(StreamRequest::class),
			$this->createStub(ConversationsRequest::class),
			$this->createStub(LoggerInterface::class),
			$this->createStub(LocalRecordResolver::class),
			$this->createStub(PostRefs::class),
		);
		$service->fromSocial($viewer, 'https://social.example/@alice/status/42', true);
	}

	private function postRefs(string $postId, string $uri): PostRefs {
		$postRefs = $this->createMock(PostRefs::class);
		$postRefs->expects($this->once())->method('strongRef')->with($postId)->willReturn(['uri' => $uri, 'cid' => 'bafyreia']);

		return $postRefs;
	}

	/** @return array<string, array{string, bool}> */
	public static function muteMethods(): array {
		return [
			'mute' => [BlueskyThreadMutes::MUTE, true],
			'unmute' => [BlueskyThreadMutes::UNMUTE, false],
		];
	}
}
