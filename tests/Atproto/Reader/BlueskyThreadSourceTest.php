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
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\DurableCache;
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
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));
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
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$this->assertSame(4, $source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10));
		$this->assertSame(['app.bsky.feed.getPostThread', ['uri' => 'at://p/2', 'depth' => 10, 'parentHeight' => 20], 3], $queries[0]);
		$this->assertSame([
			'app.bsky.unspecced.getPostThreadV2',
			[
				'anchor' => 'at://p/2',
				'above' => 'false',
				'below' => 1,
				'branchingFactor' => 0,
				'sort' => 'oldest',
			],
		], array_slice($queries[1], 0, 2));
		$this->assertGreaterThan(0, $queries[1][2]);
		$this->assertLessThanOrEqual(2, $queries[1][2], 'V2 receives only the whole seconds left in the original budget');
	}

	public function testVisibleReadRecoversRepliesOmittedFromNestedCommentThreads(): void {
		$root = self::node('at://p/root', [self::node('at://p/child')]);
		$root['post']['replyCount'] = 1;
		$root['replies'][0]['post']['replyCount'] = 2;
		$root['replies'][0]['replies'] = [self::node('at://p/reply-1')];
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/root', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$queries = [];
		$appView->expects($this->exactly(2))->method('query')->willReturnCallback(
			static function (string $method, array $params, array $headers = [], ?int $timeout = null) use (&$queries, $root): array {
				$queries[] = [$method, $params, $timeout];
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => $root];
				}

				return ['thread' => [
					['depth' => 0, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://p/child']]],
					['depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://p/reply-1']]],
					['depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://p/reply-2']]],
				], 'hasOtherReplies' => false];
			}
		);
		$seen = [];
		$stored = [];
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static function (array $view) use (&$seen, &$stored): bool {
			$uri = (string)$view['uri'];
			$stored[] = $uri;
			$isNew = !isset($seen[$uri]);
			$seen[$uri] = true;

			return $isNew;
		});
		$store->expects($this->exactly(2))->method('refreshKnownPostCountsFromView');
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$this->assertSame(4, $source->fillVisible((new Note())->setId('https://social.test/@alice/root'), 10));
		$this->assertSame('at://p/child', $queries[1][1]['anchor'], 'the fallback targets the nested comment with missing replies');
		$this->assertSame(['at://p/root', 'at://p/child', 'at://p/reply-1', 'at://p/child', 'at://p/reply-1', 'at://p/reply-2'], $stored);
		$this->assertFalse($source->hasDeepRepliesPending(), 'all replies reported by the nested comment were recovered');
	}

	public function testVisibleFallbackReadsShareTheOriginalThreeSecondBudget(): void {
		$root = self::node('at://p/2', [self::node('at://r/1')]);
		$root['post']['replyCount'] = 2;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$timeouts = [];
		$appView->expects($this->exactly(2))->method('query')->willReturnCallback(
			static function (string $method, array $params, array $headers = [], ?int $timeout = null) use (&$timeouts, $root): array {
				$timeouts[] = $timeout;
				if ($method === 'app.bsky.feed.getPostThread') {
					usleep(1_100_000);

					return ['thread' => $root];
				}

				return ['thread' => [
					['depth' => 0, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://p/2']]],
					['depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://r/1']]],
					['depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://r/2']]],
				], 'hasOtherReplies' => false];
			}
		);
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturn(true);
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10);

		self::assertSame(3, $timeouts[0]);
		self::assertGreaterThan(0, $timeouts[1]);
		self::assertLessThan(3, $timeouts[1], 'the V2 request receives only the remaining part of the shared budget');
	}

	public function testVisibleReadSchedulesRepliesOfRecoveredDirectRepliesForTheBackgroundFill(): void {
		$root = self::node('at://p/2', [self::node('at://r/1')]);
		$root['post']['replyCount'] = 2;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->exactly(2))->method('query')->willReturnCallback(
			static function (string $method) use ($root): array {
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => $root];
				}

				return ['thread' => [
					['depth' => 0, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://p/2']]],
					['depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://r/2', 'replyCount' => 1]]],
				]];
			}
		);
		$store = $this->createMock(PostStore::class);
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10);

		self::assertTrue($source->hasDeepRepliesPending(), 'the detail request should ask for a background continuation');
	}

	public function testBackgroundFillReadsRepliesOfDirectRepliesRecoveredByV2(): void {
		$root = self::node('at://p/2', [self::node('at://r/1')]);
		$root['post']['replyCount'] = 2;
		$childThread = self::node('at://r/2', [self::node('at://r/3')]);
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$queried = [];
		$appView->expects($this->exactly(3))->method('query')->willReturnCallback(
			static function (string $method, array $params) use (&$queried, $root, $childThread): array {
				$queried[] = [$method, $params];
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => ($params['uri'] ?? '') === 'at://r/2' ? $childThread : $root];
				}

				return ['thread' => [
					['depth' => 0, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://p/2']]],
					['depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://r/2', 'replyCount' => 1]]],
				]];
			}
		);
		$stored = [];
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static function (array $view) use (&$stored): bool {
			$uri = (string)($view['uri'] ?? '');
			$isNew = !in_array($uri, $stored, true);
			$stored[] = $uri;

			return $isNew;
		});
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$source->fill((new Note())->setId('https://social.test/@alice/2'), 10);

		self::assertContains(['app.bsky.feed.getPostThread', ['uri' => 'at://r/2', 'depth' => 10, 'parentHeight' => 20]], $queried);
		self::assertContains('at://r/3', $stored, 'the recovered reply branch is walked too');
	}

	public function testMissingV2EndpointLeavesTheStableThreadUsable(): void {
		$thread = self::node('at://p/2', [self::node('at://r/1')]);
		$thread['post']['replyCount'] = 2;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->exactly(3))->method('query')->willReturnCallback(
			static function (string $method) use ($thread): array {
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => $thread];
				}
				if ($method === 'app.bsky.feed.searchPostsV2') {
					return ['posts' => []];
				}
				throw new AtprotoException('UnknownMethod');
			}
		);
		$store = $this->createMock(PostStore::class);
		$store->expects($this->exactly(2))->method('storePost')->willReturn(true);
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$this->assertSame(2, $source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10));
		$this->assertTrue($source->hasDeepRepliesPending(), 'an unavailable V2 read must be retried in the background');
	}

	public function testMissingRepliesUseTheSpeccedCursorPagedSearchFallback(): void {
		$thread = self::node('at://p/2', [self::node('at://r/1')]);
		$thread['post']['replyCount'] = 3;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$asked = [];
		$appView->expects($this->exactly(3))->method('query')->willReturnCallback(
			static function (string $method, array $params) use (&$asked, $thread): array {
				$asked[] = [$method, $params];
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => $thread];
				}
				if ($method === 'app.bsky.unspecced.getPostThreadV2') {
					throw new AtprotoException('UnknownMethod');
				}

				return [
					'posts' => [
						['uri' => 'at://r/2', 'record' => ['reply' => ['root' => ['uri' => 'at://p/2'], 'parent' => ['uri' => 'at://p/2']]]],
						['uri' => 'at://r/3', 'record' => ['reply' => ['root' => ['uri' => 'at://p/2'], 'parent' => ['uri' => 'at://r/2']]]],
					],
					'cursor' => 'next-page',
				];
			}
		);
		$stored = [];
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static function (array $post) use (&$stored): bool {
			$stored[] = $post['uri'];

			return true;
		});
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$this->assertSame(4, $source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10));
		$this->assertSame(['at://p/2', 'at://r/1', 'at://r/2', 'at://r/3'], $stored);
		$this->assertSame('app.bsky.feed.searchPostsV2', $asked[2][0]);
		$this->assertSame([
			'threadRootUri' => 'at://p/2',
			'repliesOnly' => 'true',
			'allTime' => 'true',
			'sort' => 'recent',
			'limit' => 8,
		], $asked[2][1]);
		$this->assertTrue($source->hasDeepRepliesPending(), 'a cursor beyond the visible page schedules a background continuation');
	}

	public function testNestedReplySearchUsesTheConversationRootUri(): void {
		$childUri = 'at://did:plc:alice/app.bsky.feed.post/child';
		$rootUri = 'at://did:plc:alice/app.bsky.feed.post/root';
		$thread = self::node($childUri);
		$thread['post']['replyCount'] = 2;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => $childUri, 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$asked = [];
		$appView->expects($this->exactly(3))->method('query')->willReturnCallback(
			static function (string $method, array $params) use (&$asked, $thread, $rootUri, $childUri): array {
				$asked[] = [$method, $params];
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => $thread];
				}
				if ($method === 'app.bsky.unspecced.getPostThreadV2') {
					throw new AtprotoException('UnknownMethod');
				}

				return ['posts' => [
					['uri' => 'at://did:plc:alice/app.bsky.feed.post/reply1', 'record' => ['reply' => ['root' => ['uri' => $rootUri], 'parent' => ['uri' => $childUri]]]],
					['uri' => 'at://did:plc:alice/app.bsky.feed.post/reply2', 'record' => ['reply' => ['root' => ['uri' => $rootUri], 'parent' => ['uri' => $childUri]]]],
				]];
			}
		);
		$stored = [];
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static function (array $post) use (&$stored): bool {
			$stored[] = $post['uri'];

			return true;
		});
		$post = (new Note())->setId('https://social.test/@alice/reply');
		$post->setDetailArray(PostMapper::DETAIL, ['reply_root' => ['uri' => $rootUri, 'cid' => 'bafyroot']]);
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$this->assertSame(3, $source->fillVisible($post, 10));
		$this->assertSame($rootUri, $asked[2][1]['threadRootUri']);
		$this->assertSame([$childUri, 'at://did:plc:alice/app.bsky.feed.post/reply1', 'at://did:plc:alice/app.bsky.feed.post/reply2'], $stored);
	}

	public function testNestedReplySearchFindsTheRootInTheParentChainForOlderPosts(): void {
		$childUri = 'at://did:plc:alice/app.bsky.feed.post/child';
		$rootUri = 'at://did:plc:alice/app.bsky.feed.post/root';
		$thread = self::node($childUri, [], self::node($rootUri));
		$thread['post']['replyCount'] = 1;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => $childUri, 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$asked = [];
		$appView->expects($this->exactly(3))->method('query')->willReturnCallback(
			static function (string $method, array $params) use (&$asked, $thread, $rootUri, $childUri): array {
				$asked[] = [$method, $params];
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => $thread];
				}
				if ($method === 'app.bsky.unspecced.getPostThreadV2') {
					throw new AtprotoException('UnknownMethod');
				}

				return ['posts' => [[
					'uri' => 'at://did:plc:alice/app.bsky.feed.post/reply',
					'record' => ['reply' => ['root' => ['uri' => $rootUri], 'parent' => ['uri' => $childUri]]],
				]]];
			}
		);
		$stored = [];
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static function (array $post) use (&$stored): bool {
			$stored[] = $post['uri'];

			return true;
		});
		$post = (new Note())->setId('https://social.test/@alice/reply');
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$this->assertSame(3, $source->fillVisible($post, 10));
		$this->assertSame($rootUri, $asked[2][1]['threadRootUri']);
		$this->assertSame([$rootUri, $childUri, 'at://did:plc:alice/app.bsky.feed.post/reply'], $stored);
	}

	public function testBackgroundSearchFallbackFollowsTheCursorToTheNextReplyPage(): void {
		$thread = self::node('at://p/2', [self::node('at://r/1')]);
		$thread['post']['replyCount'] = 3;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$searchParams = [];
		$appView->expects($this->exactly(4))->method('query')->willReturnCallback(
			static function (string $method, array $params) use (&$searchParams, $thread): array {
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => $thread];
				}
				if ($method === 'app.bsky.unspecced.getPostThreadV2') {
					throw new AtprotoException('UnknownMethod');
				}
				$searchParams[] = $params;
				if (!isset($params['cursor'])) {
					return [
						'posts' => [
							['uri' => 'at://r/2', 'record' => ['reply' => ['root' => ['uri' => 'at://p/2'], 'parent' => ['uri' => 'at://p/2']]]],
						],
						'cursor' => 'page-two',
					];
				}

				return [
					'posts' => [
						['uri' => 'at://r/3', 'record' => ['reply' => ['root' => ['uri' => 'at://p/2'], 'parent' => ['uri' => 'at://p/2']]]],
					],
				];
			}
		);
		$stored = [];
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static function (array $post) use (&$stored): bool {
			$stored[] = $post['uri'];

			return true;
		});
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$this->assertSame(4, $source->fill((new Note())->setId('https://social.test/@alice/2'), 10));
		$this->assertSame(['at://p/2', 'at://r/1', 'at://r/2', 'at://r/3'], $stored);
		$this->assertSame('page-two', $searchParams[1]['cursor']);
	}

	public function testBackgroundSearchFallbackResumesAfterItsPageBudget(): void {
		$thread = self::node('at://p/2');
		$thread['post']['replyCount'] = 21;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$searchCursors = [];
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(static function (string $method, array $params) use ($thread, &$searchCursors): array {
			if ($method === 'app.bsky.feed.getPostThread') {
				return ['thread' => $thread];
			}
			if ($method === 'app.bsky.unspecced.getPostThreadV2') {
				return ['thread' => [[
					'depth' => 0,
					'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://p/2']],
				]], 'hasOtherReplies' => true];
			}
			$cursor = (string)($params['cursor'] ?? '');
			$searchCursors[] = $cursor;
			$page = $cursor === '' ? 1 : ((int)substr($cursor, 6) + 1);
			$answer = ['posts' => [[
				'uri' => 'at://r/' . $page,
				'record' => ['reply' => ['root' => ['uri' => 'at://p/2'], 'parent' => ['uri' => 'at://p/2']]],
			]]];
			if ($page < 21) {
				$answer['cursor'] = 'cursor' . $page;
			}

			return $answer;
		});
		$state = [];
		$cache = $this->createMock(DurableCache::class);
		$cache->method('getShared')->willReturnCallback(static function (string $namespace, string $key) use (&$state): mixed {
			return $state[$namespace][$key] ?? null;
		});
		$cache->method('setShared')->willReturnCallback(static function (string $namespace, string $key, mixed $value) use (&$state): void {
			$state[$namespace][$key] = $value;
		});
		$cache->method('removeShared')->willReturnCallback(static function (string $namespace, string $key) use (&$state): void {
			unset($state[$namespace][$key]);
		});
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturn(false);
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $cache);
		$post = (new Note())->setId('https://social.test/@alice/2');

		$source->fill($post, 150);
		$this->assertTrue($source->hasDeepRepliesPending(), 'a cursor left after the per-run page cap remains incomplete');
		$this->assertSame(20, count($searchCursors));
		$this->assertSame('cursor20', $state['social.atproto.thread.search-cursors'][hash('sha256', "at://p/2\0at://p/2")]);

		$source->fill($post, 150);
		$this->assertSame('cursor20', $searchCursors[20], 'the next background pass starts at the saved page');
		$this->assertFalse($source->hasDeepRepliesPending(), 'the cursor is cleared when the final page is read');
		$this->assertArrayNotHasKey(hash('sha256', "at://p/2\0at://p/2"), $state['social.atproto.thread.search-cursors'] ?? []);
	}

	public function testBackgroundThreadBranchesResumeAfterThePerRunReadLimit(): void {
		$postUri = 'at://did:plc:alice/app.bsky.feed.post/root';
		$continuations = array_map(static fn (int $index): string => 'at://did:plc:alice/app.bsky.feed.post/reply-' . $index, range(1, 21));
		$state = ['social.atproto.thread.continuations' => [hash('sha256', $postUri) => $continuations]];
		$cache = $this->createMock(DurableCache::class);
		$cache->method('getShared')->willReturnCallback(static function (string $namespace, string $key) use (&$state): mixed {
			return $state[$namespace][$key] ?? null;
		});
		$cache->method('setShared')->willReturnCallback(static function (string $namespace, string $key, mixed $value) use (&$state): void {
			$state[$namespace][$key] = $value;
		});
		$cache->method('removeShared')->willReturnCallback(static function (string $namespace, string $key) use (&$state): void {
			unset($state[$namespace][$key]);
		});
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => $postUri, 'cid' => 'bafy']);
		$queries = [];
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(static function (string $method, array $params) use (&$queries, $postUri): array {
			$queries[] = $params['uri'];

			return ['thread' => self::node($params['uri'])];
		});
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturn(true);
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $cache);
		$post = (new Note())->setId('https://social.test/@alice/root');

		$source->fill($post, 150);
		$this->assertTrue($source->hasDeepRepliesPending());
		$this->assertCount(1, $state['social.atproto.thread.continuations'][hash('sha256', $postUri)]);
		$this->assertSame($continuations[20], $state['social.atproto.thread.continuations'][hash('sha256', $postUri)][0]);

		$source->fill($post, 150);
		$this->assertFalse($source->hasDeepRepliesPending());
		$this->assertArrayNotHasKey(hash('sha256', $postUri), $state['social.atproto.thread.continuations'] ?? []);
		$this->assertSame($continuations[20], $queries[22], 'the next worker pass starts with the one unvisited branch');
	}

	public function testIncompleteV2ReplyPageRequestsABackgroundFill(): void {
		$thread = self::node('at://p/2', [self::node('at://r/1')]);
		$thread['post']['replyCount'] = 3;
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->exactly(3))->method('query')->willReturnCallback(
			static function (string $method) use ($thread): array {
				if ($method === 'app.bsky.feed.getPostThread') {
					return ['thread' => $thread];
				}

				return ['thread' => [
					['depth' => 0, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://p/2']]],
					['depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://r/1']]],
					['depth' => 1, 'value' => ['$type' => 'app.bsky.unspecced.defs#threadItemPost', 'post' => ['uri' => 'at://r/2']]],
				], 'hasOtherReplies' => false];
			}
		);
		$store = $this->createMock(PostStore::class);
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10);

		$this->assertTrue($source->hasDeepRepliesPending(), 'the V2 response did not contain all replies named by the stable view');
	}

	public function testAResponseWithoutARecognizedThreadViewIsAReadFailure(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->once())->method('query')->willReturn([]);
		$source = new BlueskyThreadSource($config, $appView, $refs, $this->createMock(PostStore::class), $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

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
		$source = new BlueskyThreadSource($config, $appView, $refs, $this->createMock(PostStore::class), $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$this->assertSame(0, $source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10));
	}

	public function testAThreadForAnotherPostIsAReadFailure(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://p/2', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->once())->method('query')->willReturn(['thread' => self::node('at://p/unrelated')]);
		$store = $this->createMock(PostStore::class);
		$store->expects($this->never())->method('storePost');
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$this->expectException(\UnexpectedValueException::class);
		$source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 10);
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
		$source = new BlueskyThreadSource($config, $appView, $refs, $store, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

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
		$source = new BlueskyThreadSource($config, $appView, $refs, $this->createMock(PostStore::class), $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));

		$source->fillVisible((new Note())->setId('https://social.test/@alice/2'), 150);
	}
}
