<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Reader\BlueskyThreadSource;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Object\Note;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyThreadSourceTest extends TestCase {
	private const VIEW = 'app.bsky.feed.defs#threadViewPost';

	private static function node(string $uri, array $replies = [], ?array $parent = null): array {
		return ['$type' => self::VIEW, 'post' => ['uri' => $uri], 'replies' => $replies] + ($parent === null ? [] : ['parent' => $parent]);
	}

	public function testTheThreadIsStoredParentsFirstThenTheRepliesLevelByLevel(): void {
		$thread = self::node('at://p/2', [
			self::node('at://r/1', [self::node('at://r/1a')]),
			['$type' => 'app.bsky.feed.defs#notFoundPost', 'uri' => 'at://gone'],
			self::node('at://r/2'),
		], self::node('at://p/1', [], self::node('at://p/0')));
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturnCallback(static fn (string $id): ?array => $id === 'https://social.test/@alice/2' ? ['uri' => 'at://p/2', 'cid' => 'bafy'] : null);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->once())->method('query')->with('app.bsky.feed.getPostThread', $this->callback(static fn (array $p): bool => $p['uri'] === 'at://p/2'))->willReturn(['thread' => $thread]);
		$stored = [];
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static function (array $post) use (&$stored): bool {
			$stored[] = $post['uri'];

			return $post['uri'] !== 'at://p/2';
		});
		$store->expects($this->once())->method('refreshKnownPostCountsFromView')->with($this->callback(static fn (array $post): bool => ($post['uri'] ?? '') === 'at://p/2'))->willReturn(true);
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger());
		$post = (new Note())->setId('https://social.test/@alice/2');

		$this->assertTrue($source->supports($post));
		$this->assertFalse($source->supports((new Note())->setId('https://social.test/@alice/9')), 'not on Bluesky');
		$this->assertSame(4, $source->fill($post, 4));
		$this->assertSame(['at://p/0', 'at://p/1', 'at://p/2', 'at://r/1', 'at://r/2'], $stored, 'the budget counts what was new');
	}

	public function testVisibleReadFillsDirectRepliesOmittedByTheStableThreadView(): void {
		$root = self::node('at://p/2', [self::node('at://r/1')]);
		$root['post']['replyCount'] = 3;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$queries = [];
		$appView->expects($this->exactly(2))->method('query')->willReturnCallback(
			static function (string $method, array $params, array $headers = [], ?int $timeout = null) use (&$queries, $root): array {
				$queries[] = [$method, $params, $timeout];
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => $root];
				}

				return ['thread' => [
					['uri' => 'at://p/2', 'depth' => 0, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://p/2']]],
					['uri' => 'at://r/1', 'depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://r/1']]],
					['uri' => 'at://r/2', 'depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://r/2']]],
					['uri' => 'at://r/3', 'depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://r/3']]],
				], 'hasOtherReplies' => false];
			}
		);
		$seen = [];
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static function (array $view) use (&$seen): bool {
			$uri = (string)$view['uri'];
			$isNew = !isset($seen[$uri]);
			$seen[$uri] = true;

			return $isNew;
		});
		$store->expects($this->exactly(2))->method('refreshKnownPostCountsFromView');
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger());

		$this->assertSame(4, $source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10));
		$this->assertSame([
			['app.bsky.feed.getPostThread', ['uri' => 'at://p/2', 'depth' => 10, 'parentHeight' => 20], 3],
			['app.bsky.unspecced.getPostThreadV2', [
				'anchor' => 'at://p/2',
				'above' => 'false',
				'below' => 1,
				'branchingFactor' => 0,
				'sort' => 'oldest',
			], 3],
		], $queries);
	}

	public function testMissingV2EndpointLeavesTheStableThreadUsable(): void {
		$thread = self::node('at://p/2', [self::node('at://r/1')]);
		$thread['post']['replyCount'] = 2;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->exactly(2))->method('query')->willReturnCallback(
			static function (string $method) use ($thread): array {
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => $thread];
				}
				throw new AtprotoException('UnknownMethod');
			}
		);
		$store = $this->createMock(PostStore::class);
		$store->expects($this->exactly(2))->method('storePost')->willReturn(true);
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger());

		$this->assertSame(2, $source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10));
	}

	public function testAResponseWithoutARecognizedThreadViewIsAReadFailure(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->once())->method('query')->willReturn([]);
		$source = new BlueskyThreadSource($config, $appView, $refs, $this->createMock(PostStore::class), $this->createMock(LabelerService::class), new NullLogger());

		$this->expectException(\UnexpectedValueException::class);
		$source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10);
	}

	public function testAnExplicitlyUnavailableThreadIsAValidEmptyResult(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->once())->method('query')->willReturn(['thread' => ['$type' => 'app.bsky.feed.defs#notFoundPost', 'uri' => 'at://p/2']]);
		$source = new BlueskyThreadSource($config, $appView, $refs, $this->createMock(PostStore::class), $this->createMock(LabelerService::class), new NullLogger());

		$this->assertSame(0, $source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10));
	}

	public function testBackgroundFillContinuesAReplyBranchPastTheAppViewDepthLimit(): void {
		$thread = self::node('at://p/0');
		$cursor = &$thread;
		for ($depth = 1; $depth <= 10; $depth++) {
			$cursor['replies'] = [self::node('at://p/' . $depth)];
			$cursor = &$cursor['replies'][0];
		}
		$cursor['post']['replyCount'] = 1;
		$continued = self::node('at://p/10', [self::node('at://p/11')]);

		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/0', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$queries = [];
		$appView->expects($this->exactly(2))->method('query')->willReturnCallback(static function (string $method, array $params) use (&$queries, $thread, $continued): array {
			$queries[] = [$method, $params];

			return count($queries) === 1 ? ['thread' => $thread] : ['thread' => $continued];
		});
		$seen = [];
		$store = $this->createMock(PostStore::class);
		$store->expects($this->exactly(13))->method('storePost')->willReturnCallback(static function (array $post) use (&$seen): bool {
			$uri = (string)$post['uri'];
			$isNew = !isset($seen[$uri]);
			$seen[$uri] = true;

			return $isNew;
		});
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger());

		$this->assertSame(12, $source->fill((new Note())->setId('https://social.test/@alice/2'), 150));
		$this->assertSame(['at://p/10'], BlueskyThreadSource::continuationsOf($thread, 10));
		$this->assertSame([
			['app.bsky.feed.getPostThread', ['uri' => 'at://p/0', 'depth' => 10, 'parentHeight' => 20]],
			['app.bsky.feed.getPostThread', ['uri' => 'at://p/10', 'depth' => 10, 'parentHeight' => 20]],
		], $queries);
	}

	public function testInteractiveReadDoesNotWaitForDeepContinuationRequests(): void {
		$thread = self::node('at://p/0');
		$cursor = &$thread;
		for ($depth = 1; $depth <= 10; $depth++) {
			$cursor['replies'] = [self::node('at://p/' . $depth)];
			$cursor = &$cursor['replies'][0];
		}
		$cursor['post']['replyCount'] = 1;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/0', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->once())->method('query')->willReturn(['thread' => $thread]);
		$source = new BlueskyThreadSource($config, $appView, $refs, $this->createMock(PostStore::class), $this->createMock(LabelerService::class), new NullLogger());

		$source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 150);
	}
}
