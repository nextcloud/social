<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Firehose\Subscriber;
use OCA\Social\Atproto\Firehose\WebSocketServer;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Conversation;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
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
	private StreamService&MockObject $streams;
	private ConversationService&MockObject $conversations;
	private ListsRequest&MockObject $lists;
	private PostDecorationService&MockObject $postDecoration;
	private ITimeFactory&MockObject $time;
	private MastodonStreamingDaemon $daemon;

	#[\Override]
	protected function setUp(): void {
		$this->clients = $this->createMock(ClientService::class);
		$this->accounts = $this->createMock(AccountService::class);
		$this->requests = $this->createMock(StreamRequest::class);
		$this->streams = $this->createMock(StreamService::class);
		$this->conversations = $this->createMock(ConversationService::class);
		$this->lists = $this->createMock(ListsRequest::class);
		$this->postDecoration = $this->createMock(PostDecorationService::class);
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
			$this->streams,
			$this->conversations,
			$this->lists,
			$this->postDecoration,
			$this->createMock(FilterService::class),
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
		$server = new WebSocketServer('127.0.0.1:' . random_int(20000, 60000));
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
		$listSubscription = $context['listSubscription'] ?? [];
		self::assertSame(5, is_array($listSubscription) ? ($listSubscription['id'] ?? null) : null);
		self::assertSame('90', $context['listSince'] ?? null);

		self::assertNull($this->invoke('onMessage', $subscriber, 0x1, '{"type":"unsubscribe","stream":"list","list":"5"}'));
		$context = $this->contextOf($subscriber);
		self::assertSame([], $context['subscriptions'] ?? []);
		self::assertArrayNotHasKey('listSubscription', $context);
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
			'listSince' => '40',
			'listSubscription' => ['id' => 5, 'list' => $list, 'since' => '40'],
			'subscriptions' => ['list' => true],
		];
		$server = new WebSocketServer('127.0.0.1:' . random_int(20000, 60000));
		try {
			$this->invoke('sendListStatuses', $server, $subscriber, new Person());
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
		$server = new WebSocketServer('127.0.0.1:' . random_int(20000, 60000));
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
		$server = new WebSocketServer('127.0.0.1:' . random_int(20000, 60000));
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
