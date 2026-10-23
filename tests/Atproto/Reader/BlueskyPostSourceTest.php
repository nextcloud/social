<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Reader\BlueskyBlockedBy;
use OCA\Social\Atproto\Reader\BlueskyPostSource;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\Discovery\PostSourceStats;
use OCA\Social\Service\Discovery\RemotePostDiscoveryBudget;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyPostSourceTest extends TestCase {
	private function budget(bool $allowed = true): RemotePostDiscoveryBudget {
		$budget = $this->createStub(RemotePostDiscoveryBudget::class);
		$budget->method('reserve')->willReturn($allowed);

		return $budget;
	}

	public function testAHashtagAndASearchAreAskedOfTheAppViewNewestFirst(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$asked = [];
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(static function (string $method, array $params, array $headers = [], ?int $timeout = null) use (&$asked): array {
			$asked[] = [$params, $headers, $timeout];

			return ['posts' => [['uri' => 'at://a/1'], ['uri' => 'at://a/2']]];
		});
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static fn (array $post): bool => $post['uri'] === 'at://a/1');
		$source = new BlueskyPostSource($config, $appView, $this->createMock(IdentityService::class), $store, $this->createMock(LabelerService::class), new NullLogger(), $this->budget());

		$this->assertSame(1, $source->tagged('nextcloud', 25, null)->postsStored);
		$this->assertSame(1, $source->matching('open source', 25, null)->postsStored);
		$this->assertSame([
			[['q' => '#nextcloud', 'tag' => ['nextcloud'], 'sort' => 'latest', 'limit' => 25], ['atproto-accept-labelers' => ''], AppViewClient::INTERACTIVE_TIMEOUT],
			[['q' => 'open source', 'sort' => 'latest', 'limit' => 25], ['atproto-accept-labelers' => ''], AppViewClient::INTERACTIVE_TIMEOUT],
		], $asked);
	}

	public function testWhoHasBlockedThePersonIsTakenFromASearchMadeAsThem(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$answer = ['posts' => [['uri' => 'at://did:plc:bob/app.bsky.feed.post/1', 'author' => ['did' => 'did:plc:bob', 'viewer' => ['blockedBy' => true]]]]];
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->once())->method('queryAs')->with(
			'did:plc:alice',
			$this->isInstanceOf(PrivateKey::class),
			'app.bsky.feed.searchPosts',
			['q' => 'cats', 'sort' => 'latest', 'limit' => 20],
			AppViewClient::INTERACTIVE_TIMEOUT,
		)->willReturn($answer);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('activeForActor')->willReturn(new Identity(1, 'https://social.test/@alice', 'did:plc:alice', 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0));
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$alice = (new Person())->setId('https://social.test/@alice');
		$blockedBy = $this->createMock(BlueskyBlockedBy::class);
		$blockedBy->expects($this->once())->method('learn')->with($alice, $answer);
		$source = new BlueskyPostSource($config, $appView, $identities, $this->createMock(PostStore::class), $this->createMock(LabelerService::class), new NullLogger(), $this->budget(), $blockedBy);

		$source->matching('cats', 20, $alice);
	}

	public function testOnlyPostsWrittenSinceTheBoundAreStored(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturn(['posts' => [
			['uri' => 'at://a/new', 'record' => ['createdAt' => '2026-10-09T12:00:00.000Z'], 'indexedAt' => '2026-10-09T12:00:01.000Z'],
			['uri' => 'at://a/backdated', 'record' => ['createdAt' => '2020-01-01T00:00:00.000Z'], 'indexedAt' => '2026-10-09T12:00:01.000Z'],
			['uri' => 'at://a/ahead', 'record' => ['createdAt' => '2030-01-01T00:00:00.000Z'], 'indexedAt' => '2026-10-01T00:00:00.000Z'],
			['uri' => 'at://a/undated'],
		]]);
		$stored = [];
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static function (array $post) use (&$stored): bool {
			$stored[] = $post['uri'];

			return true;
		});
		$source = new BlueskyPostSource($config, $appView, $this->createMock(IdentityService::class), $store, $this->createMock(LabelerService::class), new NullLogger(), $this->budget());

		$this->assertSame(1, $source->tagged('nextcloud', 20, null, (int)strtotime('2026-10-08T00:00:00Z'))->postsStored);
		$this->assertSame(['at://a/new'], $stored, 'a record dated ahead is as old as when it was seen');
	}

	public function testNothingIsAskedWhileBlueskyIsOffForTheInstance(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(false);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->never())->method('query');
		$source = new BlueskyPostSource($config, $appView, $this->createMock(IdentityService::class), $this->createMock(PostStore::class), $this->createMock(LabelerService::class), new NullLogger(), $this->budget());

		$this->assertEquals(PostSourceStats::empty(), $source->tagged('nextcloud', 20, null, 1));
	}

	public function testExhaustedSharedBudgetSkipsAppViewSearch(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->never())->method('query');
		$source = new BlueskyPostSource(
			$config,
			$appView,
			$this->createMock(IdentityService::class),
			$this->createMock(PostStore::class),
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$this->budget(false),
		);

		$this->assertEquals(PostSourceStats::empty(), $source->tagged('nextcloud', 20, null));
	}
}
