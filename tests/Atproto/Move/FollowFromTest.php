<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Move\FollowFrom;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\FollowService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Following from here whom a Bluesky account follows, read from a PDS that
 * answers its public follow records page by page.
 */
#[AllowMockObjectsWithoutExpectations]
class FollowFromTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const PDS = 'https://pds.example.com';

	/** @var list<array{method: string, query: array}> */
	private array $calls = [];
	/** @var array<string, list<array>> the PDS's pages of follow records, by cursor */
	private array $pages = [];
	private bool $enabled = true;
	private ?array $plcData;
	private FollowService&MockObject $follows;
	private FollowFrom $followFrom;

	protected function setUp(): void {
		$this->plcData = ['alsoKnownAs' => ['at://alice.bsky.social'], 'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => self::PDS]]];
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(static fn (string $method, array $params): array => $params['handle'] === 'alice.bsky.social' ? ['did' => self::DID] : throw new AtprotoException('Unable to resolve handle'));
		$plc = $this->createMock(PlcClient::class);
		$plc->method('data')->willReturnCallback(fn (): ?array => $this->plcData);
		$pds = $this->createMock(PdsClient::class);
		$pds->method('origin')->willReturnCallback(static fn (string $typed): string => str_contains($typed, 'localhost') ? throw new AtprotoException('The PDS at x could not be reached: host does not resolve to a public address') : rtrim($typed, '/'));
		$pds->method('expect')->willReturnCallback(function (string $origin, string $method, string $verb = 'GET', array $query = []): array {
			$this->calls[] = ['method' => $method, 'query' => $query];

			return $this->pages[$query['cursor'] ?? ''] ?? ['records' => []];
		});
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('resolve')->willReturnCallback(static fn (string $did): Person => (new Person())->setId('at://' . $did));
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn((new Person())->setId('https://social.test/@bob'));
		$this->follows = $this->createMock(FollowService::class);
		$this->followFrom = new FollowFrom($config, $appView, $plc, $pds, $actors, $accounts, $this->follows, new NullLogger());
	}

	private static function follow(string $subject): array {
		return ['uri' => 'at://' . self::DID . '/app.bsky.graph.follow/x', 'value' => ['$type' => 'app.bsky.graph.follow', 'subject' => $subject, 'createdAt' => '2026-10-01T00:00:00.000Z']];
	}

	public function testTheAccountIsFoundByHandleWithItsServer(): void {
		$this->assertSame(['did' => self::DID, 'pds' => self::PDS, 'handle' => 'alice.bsky.social'], $this->followFrom->resolve('@Alice.bsky.social'));
		$this->assertSame(self::DID, $this->followFrom->resolve(self::DID)['did']);
	}

	public function testWhatCannotBeFoundIsSaidAtOnceWithACode(): void {
		foreach ([
			['nobody.bsky.social', 'handle-not-found'],
			['not a handle', 'handle-invalid'],
			['did:web:alice.example', 'did-not-supported'],
		] as [$typed, $code]) {
			try {
				$this->followFrom->resolve($typed);
				$this->fail($typed);
			} catch (MoveErrorException $e) {
				$this->assertSame($code, $e->errorCode, $typed);
			}
		}
		$this->plcData = null;
		$this->assertCode('did-not-found');
		$this->plcData = ['services' => ['atproto_pds' => ['endpoint' => 'https://localhost']]];
		$this->assertCode('pds-not-public');
		$this->enabled = false;
		$this->assertCode('bluesky-off');
	}

	private function assertCode(string $code): void {
		try {
			$this->followFrom->resolve(self::DID);
			$this->fail($code);
		} catch (MoveErrorException $e) {
			$this->assertSame($code, $e->errorCode);
		}
	}

	public function testEveryFollowedAccountIsFollowedFromHereSkippingWhatIsFollowedAlready(): void {
		$this->pages = [
			'' => ['records' => [self::follow('did:plc:carol'), self::follow('did:plc:dave'), self::follow('not a did')], 'cursor' => 'p2'],
			'p2' => ['records' => [self::follow('did:plc:erin'), self::follow('did:plc:carol'), self::follow(self::DID)]],
		];
		$this->follows->method('followActor')->willReturnCallback(static fn (Person $actor, Person $target): bool => match ($target->getId()) {
			'at://did:plc:dave' => false,
			'at://did:plc:erin' => throw new FollowSameAccountException(),
			default => true,
		});
		$progress = [];

		$result = $this->followFrom->run('bob', ['did' => self::DID, 'pds' => self::PDS], function (int $done, int $total) use (&$progress): void {
			$progress[] = [$done, $total];
		});

		$this->assertSame(['followed' => 1, 'skipped' => 2, 'failed' => []], $result);
		$this->assertSame(['repo' => self::DID, 'collection' => 'app.bsky.graph.follow', 'limit' => 100], $this->calls[0]['query'], 'the public records, no password');
		$this->assertSame('p2', $this->calls[1]['query']['cursor']);
		$this->assertSame([3, 3], end($progress));
	}

	public function testOneThatFailsDoesNotStopTheRest(): void {
		$this->pages = ['' => ['records' => [self::follow('did:plc:gone'), self::follow('did:plc:carol')]]];
		$this->follows->method('followActor')->willReturnCallback(static fn (Person $actor, Person $target): bool => $target->getId() === 'at://did:plc:gone' ? throw new \RuntimeException('not found') : true);

		$this->assertSame(['followed' => 1, 'skipped' => 0, 'failed' => ['did:plc:gone' => 'not found']], $this->followFrom->run('bob', ['did' => self::DID, 'pds' => self::PDS]));
	}
}
