<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Firehose\Subscriber;
use OCA\Social\Atproto\Firehose\WebSocketServer;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\StatusDeletionEventsRequest;
use OCA\Social\Db\StatusRevisionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Announcement;
use OCA\Social\Model\Client\Conversation;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AnnouncementService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConversationService;
use OCA\Social\Service\FeedDisplayService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\MastodonStreamingDaemon;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\PostDecorationService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class MastodonStreamingDaemonTest extends TestCase {
	private ClientService&MockObject $clients;
	private AccountService&MockObject $accounts;
	private StreamRequest&MockObject $requests;
	private StatusRevisionsRequest&MockObject $statusRevisions;
	private StatusDeletionEventsRequest&MockObject $statusDeletionEvents;
	private FollowsRequest&MockObject $follows;
	private FiltersRequest&MockObject $filterRows;
	private AnnouncementService&MockObject $announcements;
	private StreamService&MockObject $streams;
	private ConversationService&MockObject $conversations;
	private ListsRequest&MockObject $lists;
	private PostDecorationService&MockObject $postDecoration;
	private FilterService&MockObject $filters;
	private ITimeFactory&MockObject $time;
	private MastodonStreamingDaemon $daemon;

	#[\Override]
	protected function setUp(): void {
		$this->clients = $this->createMock(ClientService::class);
		$this->accounts = $this->createMock(AccountService::class);
		$this->requests = $this->createMock(StreamRequest::class);
		$this->statusRevisions = $this->createMock(StatusRevisionsRequest::class);
		$this->statusRevisions->method('newestId')->willReturn(50);
		$this->statusDeletionEvents = $this->createMock(StatusDeletionEventsRequest::class);
		$this->statusDeletionEvents->method('newestId')->willReturn(12);
		$this->follows = $this->createMock(FollowsRequest::class);
		$this->filterRows = $this->createMock(FiltersRequest::class);
		$this->announcements = $this->createMock(AnnouncementService::class);
		$this->streams = $this->createMock(StreamService::class);
		$this->conversations = $this->createMock(ConversationService::class);
		$this->lists = $this->createMock(ListsRequest::class);
		$this->postDecoration = $this->createMock(PostDecorationService::class);
		$this->filters = $this->createMock(FilterService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_800_000_000);
		$this->requests->method('newestHomeNid')->willReturn('10');
		$this->requests->method('newestNidFor')->willReturn('20');
		$this->accounts->method('getActorFromUserId')->willReturn(
			(new Person())->setId('https://social.example/users/alice')
		);
		$this->daemon = new MastodonStreamingDaemon(
			$this->clients,
			$this->accounts,
			$this->requests,
			$this->statusRevisions,
			$this->statusDeletionEvents,
			$this->follows,
			$this->filterRows,
			$this->announcements,
			$this->streams,
			$this->conversations,
			$this->lists,
			$this->postDecoration,
			$this->filters,
			$this->createMock(FeedDisplayService::class),
			$this->createMock(NotificationPolicyService::class),
			$this->time,
			new NullLogger(),
		);
	}

	public function testAuthorizationUsesBearerTokenAndInitializesUserStream(): void {
		$client = $this->client(['read']);
		$this->clients->expects(self::once())->method('getFromToken')->with('token')->willReturn($client);
		$subscriber = $this->subscriber(['stream' => 'user'], ['authorization' => 'Bearer token']);

		self::assertTrue($this->invoke('authorize', $subscriber));
		self::assertSame('alice', $subscriber->context['userId']);
		self::assertSame(true, $subscriber->context['subscriptions']['user'] ?? null);
		self::assertSame('10', $subscriber->context['homeSince']);
		self::assertSame('20', $subscriber->context['notificationSince']);
		self::assertSame('20', $subscriber->context['directSince']);
	}

	public function testAuthorizationAllowsOnlyMastodonStreamingScopes(): void {
		$this->clients->method('getFromToken')->willReturnCallback(fn (string $token): SocialClient => $this->client(
			match ($token) {
				'scoped' => ['read:statuses', 'read:notifications'],
				'public' => ['read:statuses'],
				default => ['read:notifications'],
			}
		));

		self::assertTrue($this->invoke('authorize', $this->subscriber(['stream' => 'user'], ['authorization' => 'Bearer scoped'])));
		self::assertTrue($this->invoke('authorize', $this->subscriber(['stream' => 'user:notification'], ['authorization' => 'Bearer scoped'])));
		self::assertTrue($this->invoke('authorize', $this->subscriber(['stream' => 'public:local'], ['authorization' => 'Bearer public'])));
		self::assertTrue($this->invoke('authorize', $this->subscriber(['stream' => 'direct'], ['authorization' => 'Bearer public'])));
		self::assertFalse($this->invoke('authorize', $this->subscriber(['stream' => 'user'], ['authorization' => 'Bearer public'])));
		self::assertFalse($this->invoke('authorize', $this->subscriber(['stream' => 'user:notification'], ['authorization' => 'Bearer limited'])));
		self::assertFalse($this->invoke('authorize', $this->subscriber(['stream' => 'direct'], ['authorization' => 'Bearer limited'])));
		self::assertFalse($this->invoke('authorize', $this->subscriber(['stream' => 'unknown'], ['authorization' => 'Bearer scoped'])));
	}

	public function testEventStreamPathSelectsPublicStreamAndMediaFilter(): void {
		$this->clients->method('getFromToken')->willReturn($this->client(['read:statuses']));
		$subscriber = $this->subscriber(['access_token' => 'token', 'only_media' => 'true']);
		$subscriber->eventStream = true;
		$subscriber->requestPath = '/api/v1/streaming/public/local';

		self::assertTrue($this->invoke('authorize', $subscriber));
		self::assertSame(['public:local:media' => true], $subscriber->context['subscriptions']);
		self::assertSame('20', $subscriber->context['publicSince:public:local:media']);

		$remote = $this->subscriber(['access_token' => 'token']);
		$remote->eventStream = true;
		$remote->requestPath = '/api/v1/streaming/public/remote';
		self::assertTrue($this->invoke('authorize', $remote));
		self::assertSame(['public:remote' => true], $remote->context['subscriptions']);

		$hashtag = $this->subscriber(['access_token' => 'token', 'tag' => '#Nextcloud']);
		$hashtag->eventStream = true;
		$hashtag->requestPath = '/api/v1/streaming/hashtag/local';
		self::assertTrue($this->invoke('authorize', $hashtag));
		self::assertSame(['hashtag:local' => true], $hashtag->context['subscriptions']);
		/** @var array<string, array{stream: string, tag: string, local: bool, since: string}> $hashtagSubscriptions */
		$hashtagSubscriptions = $hashtag->context['hashtagSubscriptions'];
		self::assertSame('nextcloud', array_values($hashtagSubscriptions)[0]['tag']);

		$direct = $this->subscriber(['access_token' => 'token']);
		$direct->eventStream = true;
		$direct->requestPath = '/api/v1/streaming/direct';
		self::assertTrue($this->invoke('authorize', $direct));
		self::assertSame(['direct' => true], $direct->context['subscriptions']);

		$mismatched = $this->subscriber(['stream' => 'public', 'access_token' => 'token']);
		$mismatched->eventStream = true;
		$mismatched->requestPath = '/api/v1/streaming/public/local';
		self::assertFalse($this->invoke('authorize', $mismatched));
	}

	public function testDirectStreamReadsConversationUpdatesAfterItsCursor(): void {
		$this->conversations->expects(self::once())->method('getPage')
			->with(self::isInstanceOf(Person::class), ConversationService::MAX_LIMIT, 0, 0, '20', false)
			->willReturn([
				'conversations' => [(new Conversation())->setId('25')->setUnread(true)],
				'next' => 0,
				'prev' => '30',
			]);
		$subscriber = $this->subscriber();
		$subscriber->context = ['directSince' => '20', 'subscriptions' => ['direct' => true]];
		$subscriber->eventStream = true;
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendDirectConversations', $server, $subscriber, new Person());
		} finally {
			$server->shutdown();
		}
		self::assertSame('30', $this->contextOf($subscriber)['directSince'] ?? null);
		rewind($subscriber->socket);
		$wire = stream_get_contents($subscriber->socket);
		self::assertIsString($wire);
		self::assertStringContainsString('event: conversation', $wire);
		self::assertStringContainsString('"id":"25"', $wire);
	}

	public function testListStreamSubscriptionIsOwnedAndStartsAtTheNewestStatus(): void {
		$list = (new MastodonList())->setId(5);
		$viewer = (new Person())->setId('https://social.example/users/alice');
		$this->lists->expects(self::once())->method('getOwnedById')->with($viewer->getId(), 5)->willReturn($list);
		$this->lists->expects(self::once())->method('setViewer')->with($viewer);
		$this->lists->expects(self::once())->method('getTimeline')->with(
			$list,
			self::callback(static fn (ProbeOptions $options): bool => $options->getLimit() === 1)
		)->willReturn([(new Stream())->setNid('90')]);

		$subscriber = $this->subscriber();
		$subscriber->context = ['viewer' => $viewer, 'scopes' => ['read:statuses'], 'subscriptions' => []];
		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"list","list":"5"}'));
		$context = $this->contextOf($subscriber);
		self::assertSame(['list' => true], $context['subscriptions'] ?? []);
		$listSubscription = $context['listSubscriptions'][5] ?? [];
		self::assertSame(5, is_array($listSubscription) ? ($listSubscription['id'] ?? null) : null);
		self::assertSame('90', $context['listSince:5'] ?? null);

		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"unsubscribe","stream":"list","list":"5"}'));
		$context = $this->contextOf($subscriber);
		self::assertSame([], $context['subscriptions'] ?? []);
		self::assertArrayNotHasKey('listSubscriptions', $context);
	}

	public function testListStreamKeepsMultipleOwnedListsWithIndependentCursors(): void {
		$alice = (new Person())->setId('https://social.example/users/alice');
		$listFive = (new MastodonList())->setId(5);
		$listNine = (new MastodonList())->setId(9);
		$this->lists->expects(self::exactly(2))->method('getOwnedById')->willReturnMap([
			[$alice->getId(), 5, $listFive],
			[$alice->getId(), 9, $listNine],
		]);
		$this->lists->expects(self::exactly(2))->method('getTimeline')->willReturnCallback(static function (MastodonList $list): array {
			return [(new Stream())->setNid($list->getId() === 5 ? '50' : '90')];
		});
		$subscriber = $this->subscriber();
		$subscriber->context = ['viewer' => $alice, 'scopes' => ['read:statuses'], 'subscriptions' => []];

		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"list","list":"5"}'));
		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"list","list":"9"}'));
		$context = $this->contextOf($subscriber);
		self::assertSame('50', $context['listSince:5'] ?? null);
		self::assertSame('90', $context['listSince:9'] ?? null);

		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"unsubscribe","stream":"list","list":"5"}'));
		$context = $this->contextOf($subscriber);
		$listSubscriptions = is_array($context['listSubscriptions'] ?? null) ? $context['listSubscriptions'] : [];
		self::assertArrayNotHasKey(5, $listSubscriptions);
		self::assertArrayHasKey(9, $listSubscriptions);
		self::assertSame(['list' => true], $context['subscriptions'] ?? []);
	}

	public function testListStreamRejectsMissingOrUnownedLists(): void {
		$subscriber = $this->subscriber();
		$subscriber->context = [
			'viewer' => new Person(),
			'scopes' => ['read:statuses'],
			'subscriptions' => [],
		];
		$missing = json_decode($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"list"}'), true);
		self::assertSame('A valid list owned by this account is required', $missing['payload']);

		$this->lists->method('getOwnedById')->willThrowException(new \RuntimeException('not found'));
		$unowned = json_decode($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"list","list":"9"}'), true);
		self::assertSame('A valid list owned by this account is required', $unowned['payload']);
		self::assertSame([], $subscriber->context['subscriptions']);
	}

	public function testListStreamReadsTheOwnedListTimelineWithAnAscendingCursor(): void {
		$options = null;
		$list = (new MastodonList())->setId(5);
		$this->lists->expects(self::once())->method('setViewer')->with(self::isInstanceOf(Person::class));
		$this->lists->expects(self::once())->method('getTimeline')->willReturnCallback(
			static function (MastodonList $receivedList, ProbeOptions $receivedOptions) use (&$options, $list): array {
				self::assertSame($list, $receivedList);
				$options = $receivedOptions;
				return [];
			}
		);
		$subscriber = $this->subscriber();
		$subscriber->context = [
			'listSince:5' => '40',
			'subscriptions' => ['list' => true],
			'listSubscriptions' => [5 => ['id' => 5, 'list' => $list, 'since' => '40']],
		];
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendListStatuses', $server, $subscriber, new Person(), 5, $subscriber->context['listSubscriptions'][5]);
		} finally {
			$server->shutdown();
		}

		self::assertInstanceOf(ProbeOptions::class, $options);
		self::assertSame(40, $options->getSince());
		self::assertSame(50, $options->getLimit());
		self::assertTrue($options->isInverted());
	}

	public function testPublicRemoteMediaStreamUsesTheMatchingPublicTimeline(): void {
		$options = null;
		$this->streams->expects(self::once())->method('getTimeline')->willReturnCallback(
			static function (ProbeOptions $received) use (&$options): array {
				$options = $received;
				return [];
			}
		);
		$subscriber = $this->subscriber();
		$subscriber->context = [
			'subscriptions' => ['public:remote:media' => true],
			'publicSince:public:remote:media' => '20',
		];
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendPublicStatuses', $server, $subscriber, new Person(), 'public:remote:media');
		} finally {
			$server->shutdown();
		}

		self::assertInstanceOf(ProbeOptions::class, $options);
		self::assertSame(ProbeOptions::PUBLIC, $options->getProbe());
		self::assertFalse($options->isLocal());
		self::assertTrue($options->isRemote());
		self::assertTrue($options->isOnlyMedia());
		self::assertSame(20, $options->getSince());
	}

	public function testPublicStreamsReceiveOneStatusUpdatePerEditedStatus(): void {
		$statusId = 'https://social.example/users/alice/statuses/75';
		$status = (new Stream())
			->setId($statusId)
			->setVisibility(Stream::TYPE_PUBLIC)
			->setLocal(true);
		$this->statusRevisions->expects(self::once())->method('getAfterId')->with(50, 50)->willReturn([
			['id' => 51, 'stream_id_prim' => md5($statusId)],
			['id' => 52, 'stream_id_prim' => md5($statusId)],
		]);
		$this->streams->expects(self::once())->method('getStreamByIdPrim')->with(md5($statusId), true)->willReturn($status);
		$this->filters->expects(self::once())->method('apply')->willReturn([['id' => '75']]);

		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = ['revisionSince' => 50, 'subscriptions' => ['public:local' => true]];
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendEditedPublicStatuses', $server, $subscriber, new Person());
		} finally {
			$server->shutdown();
		}

		self::assertSame(52, $subscriber->context['revisionSince']);
		rewind($subscriber->socket);
		$payload = stream_get_contents($subscriber->socket);
		self::assertIsString($payload);
		self::assertSame(1, substr_count($payload, 'event: status.update'));
		self::assertStringContainsString('"id":"75"', $payload);
	}

	public function testHomeStreamReceivesEditsOnlyWhenTheStatusRemainsInItsTimeline(): void {
		$statusId = 'https://social.example/users/alice/statuses/75';
		$status = (new Stream())->setId($statusId)->setNid('75')->setVisibility(Stream::TYPE_FOLLOWERS);
		$this->statusRevisions->expects(self::once())->method('getAfterId')->willReturn([
			['id' => 51, 'stream_id_prim' => md5($statusId)],
		]);
		$this->streams->expects(self::once())->method('getStreamByIdPrim')->with(md5($statusId), true)->willReturn($status);
		$this->streams->expects(self::once())->method('getTimeline')->with(self::callback(
			static fn (ProbeOptions $options): bool => $options->getProbe() === ProbeOptions::HOME
				&& $options->getMinId() === 74
				&& $options->getMaxId() === 76
		))->willReturn([$status]);
		$this->filters->expects(self::once())->method('apply')->with([$status], Filter::CONTEXT_HOME, self::isInstanceOf(Person::class))
			->willReturn([['id' => '75']]);

		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = ['revisionSince' => 50, 'subscriptions' => ['user' => true]];
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendEditedPublicStatuses', $server, $subscriber, new Person());
		} finally {
			$server->shutdown();
		}

		rewind($subscriber->socket);
		$payload = stream_get_contents($subscriber->socket);
		self::assertIsString($payload);
		self::assertSame(1, substr_count($payload, 'event: status.update'));
	}

	public function testDirectStreamReceivesTheUpdatedConversation(): void {
		$statusId = 'https://social.example/users/alice/statuses/75';
		$status = (new Stream())->setId($statusId)->setNid('75')->setVisibility(Stream::TYPE_DIRECT);
		$conversation = (new Conversation())->setId(75);
		$this->statusRevisions->expects(self::once())->method('getAfterId')->willReturn([
			['id' => 51, 'stream_id_prim' => md5($statusId)],
		]);
		$this->streams->expects(self::once())->method('getStreamByIdPrim')->with(md5($statusId), true)->willReturn($status);
		$this->conversations->expects(self::once())->method('updatedByStatus')->with(
			self::isInstanceOf(Person::class), 75
		)->willReturn($conversation);

		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = ['revisionSince' => 50, 'subscriptions' => ['direct' => true]];
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendEditedPublicStatuses', $server, $subscriber, new Person());
		} finally {
			$server->shutdown();
		}

		rewind($subscriber->socket);
		$payload = stream_get_contents($subscriber->socket);
		self::assertIsString($payload);
		self::assertStringContainsString('event: conversation', $payload);
		self::assertStringNotContainsString('event: status.update', $payload);
	}

	public function testPublicStreamReceivesDeletedStatusId(): void {
		$this->statusDeletionEvents->expects(self::once())->method('getAfterId')->with(12, 50)->willReturn([
			['id' => 13, 'status_nid' => '75', 'hashtags' => [], 'local' => true, 'media' => false, 'visibility' => Stream::TYPE_PUBLIC, 'recipients' => [], 'audience_prim' => null],
		]);
		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = ['deleteSince' => 12, 'subscriptions' => ['public:local' => true]];
		$server = new WebSocketServer('127.0.0.1:' . random_int(20000, 60000));
		try {
			$this->invoke('sendDeletedStatuses', $server, $subscriber);
		} finally {
			$server->shutdown();
		}

		self::assertSame(13, $subscriber->context['deleteSince']);
		rewind($subscriber->socket);
		$payload = stream_get_contents($subscriber->socket);
		self::assertIsString($payload);
		self::assertStringContainsString('event: delete', $payload);
		self::assertStringContainsString('data: 75', $payload);
	}

	public function testPublicMediaStreamDoesNotReceiveDeletedTextStatus(): void {
		$this->statusDeletionEvents->expects(self::once())->method('getAfterId')->willReturn([
			['id' => 13, 'status_nid' => '75', 'hashtags' => [], 'local' => true, 'media' => false, 'visibility' => Stream::TYPE_PUBLIC, 'recipients' => [], 'audience_prim' => null],
		]);
		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = ['deleteSince' => 12, 'subscriptions' => ['public:local:media' => true]];
		$server = new WebSocketServer('127.0.0.1:' . random_int(20000, 60000));
		try {
			$this->invoke('sendDeletedStatuses', $server, $subscriber);
		} finally {
			$server->shutdown();
		}

		rewind($subscriber->socket);
		$payload = stream_get_contents($subscriber->socket);
		self::assertIsString($payload);
		self::assertStringNotContainsString('event: delete', $payload);
	}

	public function testDirectDeletionIsSentOnlyToOriginalRecipients(): void {
		$this->statusDeletionEvents->expects(self::once())->method('getAfterId')->willReturn([
			['id' => 13, 'status_nid' => '75', 'hashtags' => [], 'local' => true, 'media' => false, 'visibility' => Stream::TYPE_DIRECT, 'recipients' => [md5('https://example.test/users/alice')], 'audience_prim' => null],
		]);
		$viewer = (new Person())->setId('https://example.test/users/alice');
		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = ['deleteSince' => 12, 'viewer' => $viewer, 'subscriptions' => ['direct' => true]];
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendDeletedStatuses', $server, $subscriber);
		} finally {
			$server->shutdown();
		}

		rewind($subscriber->socket);
		$payload = stream_get_contents($subscriber->socket);
		self::assertIsString($payload);
		self::assertStringContainsString('event: delete', $payload);
		self::assertStringContainsString('data: 75', $payload);
	}

	public function testDirectDeletionIsNotSentToNonRecipient(): void {
		$this->statusDeletionEvents->expects(self::once())->method('getAfterId')->willReturn([
			['id' => 13, 'status_nid' => '75', 'hashtags' => [], 'local' => true, 'media' => false, 'visibility' => Stream::TYPE_DIRECT, 'recipients' => [md5('https://example.test/users/alice')], 'audience_prim' => null],
		]);
		$viewer = (new Person())->setId('https://example.test/users/bob');
		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = ['deleteSince' => 12, 'viewer' => $viewer, 'subscriptions' => ['direct' => true]];
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendDeletedStatuses', $server, $subscriber);
		} finally {
			$server->shutdown();
		}

		rewind($subscriber->socket);
		$payload = stream_get_contents($subscriber->socket);
		self::assertIsString($payload);
		self::assertStringNotContainsString('event: delete', $payload);
	}

	public function testFollowersOnlyDeletionReachesAnOriginalFollower(): void {
		$collectionPrim = md5('https://example.test/users/alice/followers');
		$this->statusDeletionEvents->expects(self::once())->method('getAfterId')->willReturn([
			[
				'id' => 13,
				'status_nid' => '75',
				'hashtags' => [],
				'local' => true,
				'media' => false,
				'visibility' => Stream::TYPE_FOLLOWERS,
				'recipients' => [md5('https://example.test/users/alice'), $collectionPrim],
				'audience_prim' => $collectionPrim,
			],
		]);
		$this->follows->expects(self::once())->method('followsCollection')
			->with('https://example.test/users/bob', $collectionPrim)->willReturn(true);
		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = [
			'deleteSince' => 12,
			'viewer' => (new Person())->setId('https://example.test/users/bob'),
			'subscriptions' => ['user' => true],
		];
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendDeletedStatuses', $server, $subscriber);
		} finally {
			$server->shutdown();
		}

		rewind($subscriber->socket);
		$payload = stream_get_contents($subscriber->socket);
		self::assertIsString($payload);
		self::assertStringContainsString('event: delete', $payload);
		self::assertStringContainsString('data: 75', $payload);
	}

	public function testFollowersOnlyDeletionDoesNotReachAnUnrelatedUser(): void {
		$collectionPrim = md5('https://example.test/users/alice/followers');
		$this->statusDeletionEvents->expects(self::once())->method('getAfterId')->willReturn([
			[
				'id' => 13,
				'status_nid' => '75',
				'hashtags' => [],
				'local' => true,
				'media' => false,
				'visibility' => Stream::TYPE_FOLLOWERS,
				'recipients' => [md5('https://example.test/users/alice'), $collectionPrim],
				'audience_prim' => $collectionPrim,
			],
		]);
		$this->follows->expects(self::once())->method('followsCollection')
			->with('https://example.test/users/bob', $collectionPrim)->willReturn(false);
		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = [
			'deleteSince' => 12,
			'viewer' => (new Person())->setId('https://example.test/users/bob'),
			'subscriptions' => ['user' => true],
		];
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendDeletedStatuses', $server, $subscriber);
		} finally {
			$server->shutdown();
		}

		rewind($subscriber->socket);
		$payload = stream_get_contents($subscriber->socket);
		self::assertIsString($payload);
		self::assertStringNotContainsString('event: delete', $payload);
	}

	public function testHashtagSubscriptionRequiresTagAndSupportsUnsubscribe(): void {
		$subscriber = $this->subscriber();
		$subscriber->context = ['scopes' => ['read:statuses'], 'subscriptions' => [], 'publicSince:public' => '40'];
		$missingTag = json_decode($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"hashtag"}'), true);
		self::assertSame('A valid tag is required', $missingTag['payload']);

		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"hashtag","tag":"#Nextcloud"}'));
		/** @var array<string, bool> $subscriptions */
		$subscriptions = $subscriber->context['subscriptions'];
		/** @var array<string, mixed> $context */
		$context = $subscriber->context;
		/** @var array<string, array{stream: string, tag: string, local: bool, since: string}> $hashtagSubscriptions */
		$hashtagSubscriptions = $context['hashtagSubscriptions'] ?? [];
		self::assertSame(['hashtag' => true], $subscriptions);
		$subscription = array_values($hashtagSubscriptions)[0];
		self::assertSame('nextcloud', $subscription['tag']);
		self::assertSame('40', $subscription['since']);

		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"unsubscribe","stream":"hashtag","tag":"nextcloud"}'));
		/** @var array<string, mixed> $context */
		$context = $subscriber->context;
		self::assertSame([], $context['subscriptions']);
		self::assertSame([], $context['hashtagSubscriptions'] ?? []);
	}

	public function testHashtagSubscriptionsAreBoundedPerConnection(): void {
		$subscriber = $this->subscriber();
		$subscriber->context = ['scopes' => ['read:statuses'], 'subscriptions' => [], 'publicSince:public' => '40'];
		for ($i = 0; $i < 20; $i++) {
			self::assertNull($this->invoke('onMessage', $subscriber, 0x1, json_encode([
				'type' => 'subscribe', 'stream' => 'hashtag', 'tag' => 'tag' . $i,
			], JSON_THROW_ON_ERROR)));
		}
		$error = json_decode($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"hashtag","tag":"tag20"}'), true);
		self::assertSame('Too many hashtag subscriptions', $error['payload']);
		/** @var array<string, mixed> $context */
		$context = $subscriber->context;
		/** @var array<string, array{stream: string, tag: string, local: bool, since: string}> $hashtagSubscriptions */
		$hashtagSubscriptions = $context['hashtagSubscriptions'] ?? [];
		self::assertCount(20, $hashtagSubscriptions);
	}

	public function testHashtagStreamUsesTheMatchingTimelineAndLocalFilter(): void {
		$options = null;
		$this->streams->expects(self::once())->method('getTimeline')->willReturnCallback(
			static function (ProbeOptions $received) use (&$options): array {
				$options = $received;
				return [];
			}
		);
		$subscriber = $this->subscriber();
		$subscriber->context = ['subscriptions' => ['hashtag:local' => true]];
		$server = new WebSocketServer('127.0.0.1:0');
		try {
			$this->invoke('sendHashtagStatuses', $server, $subscriber, new Person(), 'key', [
				'stream' => 'hashtag:local', 'tag' => 'nextcloud', 'local' => true, 'since' => '30',
			]);
		} finally {
			$server->shutdown();
		}

		self::assertInstanceOf(ProbeOptions::class, $options);
		self::assertSame(ProbeOptions::HASHTAG, $options->getProbe());
		self::assertSame('nextcloud', $options->getArgument());
		self::assertTrue($options->isLocal());
		self::assertSame(30, $options->getSince());
	}

	public function testEventStreamPathSelectsAndLocksItsStream(): void {
		$this->clients->method('getFromToken')->willReturn($this->client(['read']));
		$subscriber = $this->subscriber(['access_token' => 'token'], []);
		$subscriber->eventStream = true;
		$subscriber->requestPath = '/api/v1/streaming/user/notification';

		self::assertTrue($this->invoke('authorize', $subscriber));
		self::assertSame(['user:notification' => true], $subscriber->context['subscriptions']);

		$mismatched = $this->subscriber(['stream' => 'user', 'access_token' => 'token']);
		$mismatched->eventStream = true;
		$mismatched->requestPath = '/api/v1/streaming/user/notification';
		self::assertFalse($this->invoke('authorize', $mismatched));
	}

	public function testWebSocketSubscriptionChangesOnlySupportedStreams(): void {
		$subscriber = $this->subscriber();
		$subscriber->context = ['scopes' => ['read'], 'subscriptions' => []];

		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"user"}'));
		/** @var array<string, bool> $subscriptions */
		$subscriptions = $subscriber->context['subscriptions'];
		self::assertSame(['user' => true], $subscriptions);
		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"unsubscribe","stream":"user"}'));
		self::assertSame([], $subscriber->context['subscriptions']);
		$subscriber->context['scopes'] = ['read:statuses'];
		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"public:local"}'));
		self::assertSame(['public:local' => true], $subscriber->context['subscriptions']);
		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"hashtag:local","tag":"social"}'));
		self::assertSame(['public:local' => true, 'hashtag:local' => true], $subscriber->context['subscriptions']);

		$error = json_decode($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"unknown"}'), true);
		self::assertSame('error', $error['event']);
		self::assertSame('Unknown stream', $error['payload']);
	}

	public function testWebSocketSubscriptionRequiresTheStreamingScopes(): void {
		$subscriber = $this->subscriber();
		$subscriber->context = ['scopes' => ['read:notifications'], 'subscriptions' => []];

		$error = json_decode($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"user"}'), true);
		self::assertSame('Insufficient scope', $error['payload']);
		self::assertSame([], $subscriber->context['subscriptions']);
	}

	public function testChangedFiltersEmitMastodonWebSocketEventWithoutPayload(): void {
		$filter = (new Filter())->setId(7)->setTitle('spoilers');
		$this->filterRows->expects(self::once())->method('getByActor')->willReturn([$filter]);
		$subscriber = $this->subscriber();
		$subscriber->context = ['filterCheckedAt' => 1_799_999_990, 'filterFingerprint' => hash('sha256', serialize([]))];
		$server = $this->createMock(WebSocketServer::class);
		$server->expects(self::once())->method('send')->with($subscriber, (string)json_encode([
			'stream' => ['user'],
			'event' => 'filters_changed',
		]));

		$this->invoke('sendFilterChanges', $server, $subscriber, new Person(), ['user' => true], 1_800_000_000);
	}

	public function testChangedFiltersUseMastodonSseUndefinedPayload(): void {
		$filter = (new Filter())->setId(7)->setTitle('spoilers');
		$this->filterRows->expects(self::once())->method('getByActor')->willReturn([$filter]);
		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = ['filterCheckedAt' => 1_799_999_990, 'filterFingerprint' => hash('sha256', serialize([]))];
		$server = $this->createMock(WebSocketServer::class);
		$server->expects(self::once())->method('send')->with($subscriber, "event: filters_changed\ndata: undefined\n\n");

		$this->invoke('sendFilterChanges', $server, $subscriber, new Person(), ['user' => true], 1_800_000_000);
	}

	public function testFilterChangesAreNotSentOnNotificationOnlyStream(): void {
		$this->filterRows->expects(self::never())->method('getByActor');
		$subscriber = $this->subscriber();
		$subscriber->context = ['filterCheckedAt' => 1_799_999_990, 'filterFingerprint' => hash('sha256', serialize([]))];
		$server = $this->createMock(WebSocketServer::class);
		$server->expects(self::never())->method('send');

		$this->invoke('sendFilterChanges', $server, $subscriber, new Person(), ['user:notification' => true], 1_800_000_000);
	}

	public function testUnchangedFiltersDoNotEmitAnEvent(): void {
		$this->filterRows->method('getByActor')->willReturn([]);
		$subscriber = $this->subscriber();
		$subscriber->context = ['filterCheckedAt' => 1_799_999_990, 'filterFingerprint' => hash('sha256', serialize([]))];
		$server = $this->createMock(WebSocketServer::class);
		$server->expects(self::never())->method('send');

		$this->invoke('sendFilterChanges', $server, $subscriber, new Person(), ['user' => true], 1_800_000_000);
	}

	public function testNewAnnouncementsAreStreamedToTheUser(): void {
		$announcement = (new Announcement())->setId(9)->setText('Maintenance tonight')->setPublishedAt(1_800_000_000)->setUpdatedAt(1_800_000_000);
		$this->announcements->expects(self::once())->method('active')->willReturn([$announcement]);
		$subscriber = $this->subscriber();
		$subscriber->context = ['announcementCheckedAt' => 1_799_999_990, 'announcementState' => []];
		$server = $this->createMock(WebSocketServer::class);
		$server->expects(self::once())->method('send')->with($subscriber, (string)json_encode([
			'stream' => ['user'],
			'event' => 'announcement',
			'payload' => json_encode($announcement, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		$this->invoke('sendAnnouncementChanges', $server, $subscriber, new Person(), ['user' => true], 1_800_000_000);
	}

	public function testReactionCountChangesAreStreamed(): void {
		$before = (new Announcement())->setId(9)->setText('Maintenance tonight');
		$after = (new Announcement())->setId(9)->setText('Maintenance tonight')
			->setReactions(['😀' => ['count' => 1, 'me' => false]]);
		$this->announcements->expects(self::once())->method('active')->willReturn([$after]);
		$subscriber = $this->subscriber();
		$subscriber->context = ['announcementCheckedAt' => 1_799_999_990, 'announcementState' => ['9' => $before->jsonSerialize()]];
		$server = $this->createMock(WebSocketServer::class);
		$server->expects(self::once())->method('send')->with($subscriber, (string)json_encode([
			'stream' => ['user'],
			'event' => 'announcement.reaction',
			'payload' => json_encode(['name' => '😀', 'count' => 1, 'announcement_id' => '9'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		$this->invoke('sendAnnouncementChanges', $server, $subscriber, new Person(), ['user' => true], 1_800_000_000);
	}

	public function testRemovedAnnouncementsUseRawIdForSseDeleteEvent(): void {
		$this->announcements->expects(self::once())->method('active')->willReturn([]);
		$announcement = (new Announcement())->setId(9)->setText('Maintenance tonight');
		$subscriber = $this->subscriber();
		$subscriber->eventStream = true;
		$subscriber->context = ['announcementCheckedAt' => 1_799_999_990, 'announcementState' => ['9' => $announcement->jsonSerialize()]];
		$server = $this->createMock(WebSocketServer::class);
		$server->expects(self::once())->method('send')->with($subscriber, "event: announcement.delete\ndata: 9\n\n");

		$this->invoke('sendAnnouncementChanges', $server, $subscriber, new Person(), ['user' => true], 1_800_000_000);
	}

	public function testAnnouncementEventsAreNotSentOnNotificationOnlyStream(): void {
		$this->announcements->expects(self::never())->method('active');
		$subscriber = $this->subscriber();
		$subscriber->context = ['announcementCheckedAt' => 1_799_999_990, 'announcementState' => []];
		$server = $this->createMock(WebSocketServer::class);
		$server->expects(self::never())->method('send');

		$this->invoke('sendAnnouncementChanges', $server, $subscriber, new Person(), ['user:notification' => true], 1_800_000_000);
	}

	public function testWebSocketDeletePayloadIsAStringNotAJsonString(): void {
		$subscriber = $this->subscriber();
		$server = $this->createMock(WebSocketServer::class);
		$server->expects(self::once())->method('send')->with($subscriber, (string)json_encode([
			'stream' => ['public'],
			'event' => 'delete',
			'payload' => '75',
		]));

		$this->invoke('send', $server, $subscriber, ['public'], 'delete', '75');
	}

	private function client(array $scopes): SocialClient {
		return (new SocialClient())->setAuthId(1)->setAuthUserId('alice')->setAuthScopes($scopes);
	}

	private function subscriber(array $query = [], array $headers = []): Subscriber {
		$subscriber = new Subscriber(1, tmpfile());
		$subscriber->query = $query;
		$subscriber->headers = $headers;

		return $subscriber;
	}

	/** @return array<string, mixed> */
	private function contextOf(Subscriber $subscriber): array {
		return $subscriber->context;
	}

	private function invoke(string $method, mixed ...$args): mixed {
		$reflection = new \ReflectionMethod($this->daemon, $method);

		return $reflection->invokeArgs($this->daemon, $args);
	}
}
