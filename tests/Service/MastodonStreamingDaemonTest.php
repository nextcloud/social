<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Firehose\Subscriber;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FilterService;
use OCA\Social\Service\MastodonStreamingDaemon;
use OCA\Social\Service\NotificationPolicyService;
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
	private ITimeFactory&MockObject $time;
	private MastodonStreamingDaemon $daemon;

	#[\Override]
	protected function setUp(): void {
		$this->clients = $this->createMock(ClientService::class);
		$this->accounts = $this->createMock(AccountService::class);
		$this->requests = $this->createMock(StreamRequest::class);
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
			$this->createMock(StreamService::class),
			$this->createMock(FilterService::class),
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
	}

	public function testAuthorizationAllowsOnlyMastodonStreamingScopes(): void {
		$this->clients->method('getFromToken')->willReturnCallback(fn (string $token): SocialClient => $this->client(
			$token === 'scoped' ? ['read:statuses', 'read:notifications'] : ['read:notifications']
		));

		self::assertTrue($this->invoke('authorize', $this->subscriber(['stream' => 'user'], ['authorization' => 'Bearer scoped'])));
		self::assertTrue($this->invoke('authorize', $this->subscriber(['stream' => 'user:notification'], ['authorization' => 'Bearer scoped'])));
		self::assertFalse($this->invoke('authorize', $this->subscriber(['stream' => 'user:notification'], ['authorization' => 'Bearer limited'])));
		self::assertFalse($this->invoke('authorize', $this->subscriber(['stream' => 'unknown'], ['authorization' => 'Bearer scoped'])));
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

		$error = json_decode($this->invoke('onMessage', $subscriber, 0x1, '{"type":"subscribe","stream":"public"}'), true);
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

	private function invoke(string $method, mixed ...$args): mixed {
		$reflection = new \ReflectionMethod($this->daemon, $method);

		return $reflection->invokeArgs($this->daemon, $args);
	}
}
