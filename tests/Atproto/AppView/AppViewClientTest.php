<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\AppView;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AppViewNotFoundException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Service\CurlService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AppViewClientTest extends TestCase {
	public function testAProcedureIsAPostOfItsInputAsThePerson(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('appViewAuth')->willReturn('https://api.bsky.app');
		$config->method('appViewDid')->willReturn('did:web:api.bsky.app');
		$auth = $this->createMock(ServiceAuth::class);
		$auth->method('token')->with($this->anything(), 'did:plc:alice', 'did:web:api.bsky.app', 'app.bsky.notification.putActivitySubscription')->willReturn('signed');
		$sent = [];
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(static function (string $verb, string $url, array $options, &$contentType, &$status) use (&$sent): string {
			$sent = [$verb, $url, $options['headers']['Authorization'], $options['headers']['Content-Type'] ?? '', json_decode($options['body'], true)];
			$status = 200;

			return '{"subject":"did:plc:bob"}';
		});

		$answer = (new AppViewClient($config, $auth, $curl, new NullLogger()))->procedureAs('did:plc:alice', PrivateKey::generate(Curve::K256), 'app.bsky.notification.putActivitySubscription', ['subject' => 'did:plc:bob']);

		$this->assertSame(['subject' => 'did:plc:bob'], $answer);
		$this->assertSame(['post', 'https://api.bsky.app/xrpc/app.bsky.notification.putActivitySubscription', 'Bearer signed', 'application/json', ['subject' => 'did:plc:bob']], $sent);
	}

	public function testAProcedureWithoutOutputAnswersNothingAndAQueryMustAnswerJson(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('appView')->willReturn('https://public.api.bsky.app');
		$config->method('appViewAuth')->willReturn('https://api.bsky.app');
		$config->method('appViewDid')->willReturn('did:web:api.bsky.app');
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(static function (string $verb, string $url, array $options, &$contentType, &$status): string {
			$status = 200;

			return '';
		});
		$client = new AppViewClient($config, $this->createMock(ServiceAuth::class), $curl, new NullLogger());

		$this->assertSame([], $client->procedureAs('did:plc:alice', PrivateKey::generate(Curve::K256), 'app.bsky.graph.muteActorList', ['list' => 'at://did:plc:bob/app.bsky.graph.list/3k']));
		$this->expectException(AtprotoException::class);
		$client->query('app.bsky.actor.getProfile', ['actor' => 'did:plc:bob']);
	}

	/** An AppView not reached says so, for which call, and not what went wrong on the way: that is logged. */
	public function testAnAppViewNotReachedSaysNoMoreThanThat(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('appView')->willReturn('https://public.api.bsky.app');
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willThrowException(new \RuntimeException('cURL error 7: Failed to connect to 10.0.0.7 port 443'));
		$client = new AppViewClient($config, $this->createMock(ServiceAuth::class), $curl, new NullLogger());

		try {
			$client->query('app.bsky.actor.getProfile', ['actor' => 'did:plc:bob']);
			$this->fail('answered');
		} catch (AtprotoException $e) {
			$this->assertSame('AppView could not be reached for app.bsky.actor.getProfile', $e->getMessage());
		}
	}

	/** An account the AppView no longer serves is not there, and the refusal says why. */
	public function testAnAccountNoLongerServedIsNotFoundWithTheErrorNamed(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('appView')->willReturn('https://public.api.bsky.app');
		$curl = $this->createMock(CurlService::class);
		$curl->method('doRequest')->willReturnCallback(static function (string $verb, string $url, array $options, &$contentType, &$status): string {
			$status = 400;

			return '{"error":"AccountDeactivated","message":"Account is deactivated"}';
		});
		$client = new AppViewClient($config, $this->createMock(ServiceAuth::class), $curl, new NullLogger());

		try {
			$client->query('app.bsky.feed.getAuthorFeed', ['actor' => 'did:plc:bob']);
			$this->fail('answered');
		} catch (AppViewNotFoundException $e) {
			$this->assertSame('AccountDeactivated', $e->error);
			$this->assertSame('app.bsky.feed.getAuthorFeed: Account is deactivated', $e->getMessage());
		}
	}
}
