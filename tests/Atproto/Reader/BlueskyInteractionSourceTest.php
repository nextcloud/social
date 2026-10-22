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
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\BlueskyInteractionSource;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\DurableCache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use UnexpectedValueException;

#[AllowMockObjectsWithoutExpectations]
class BlueskyInteractionSourceTest extends TestCase {
	public function testReactionIsKeptWhenCachingTheActorFails(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'likes' => [[
			'actor' => ['did' => 'did:plc:ann', 'handle' => 'ann.test'],
		]]]);
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('cached')->willReturn(null);
		$actors->expects($this->once())
			->method('store')
			->willThrowException(new \RuntimeException('temporary actor store failure'));
		$mapper = $this->createMock(ActorMapper::class);
		$mapper->method('person')->willReturn(new Person());
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$this->createMock(PostStore::class),
			$mapper,
			$actors,
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$this->createMock(DurableCache::class),
		);

		$this->assertSame(
			['https://bsky.app/profile/did:plc:ann'],
			$source->actors((new Note())->setId('https://social.test/@alice/1'), 'Like', 10),
		);
	}

	public function testReactionViewRefreshesCachedAvatarWithoutDroppingTheProfile(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'likes' => [[
			'actor' => ['did' => 'did:plc:ann', 'handle' => 'ann.test', 'avatar' => 'https://cdn.bsky.app/avatar/new.jpeg'],
		]]]);
		$cached = (new Person())->setName('Cached display name')->setSummary('Cached full bio');
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('cached')->with('did:plc:ann')->willReturn($cached);
		$actors->expects($this->once())->method('store')->with($this->callback(static function (Person $actor): bool {
			return $actor->getName() === 'Cached display name'
				&& $actor->getSummary() === 'Cached full bio'
				&& $actor->hasIcon()
				&& $actor->getIcon()?->getUrl() === 'https://cdn.bsky.app/avatar/new.jpeg';
		}));
		$mapper = $this->createMock(ActorMapper::class);
		$mapper->method('person')->willReturnCallback(static function (array $profile): Person {
			$person = new Person();
			$avatar = (new Document())->setUrl((string)$profile['avatar']);
			$person->setIcon($avatar);

			return $person;
		});
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$this->createMock(PostStore::class),
			$mapper,
			$actors,
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$this->createMock(DurableCache::class),
		);

		$this->assertSame(['https://bsky.app/profile/did:plc:ann'], $source->actors((new Note())->setId('https://social.test/@alice/1'), 'Like', 10));
	}

	public function testLikersRepostersAndQuotesAreAskedOfTheAppView(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(static fn (string $method): array => match ($method) {
			'app.bsky.feed.getLikes' => ['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'likes' => [
				['actor' => ['did' => 'did:plc:ann', 'handle' => 'ann.test'], 'createdAt' => 'x'],
				['actor' => ['did' => 'did:plc:ben', 'handle' => 'ben.test'], 'createdAt' => 'x'],
			]],
			'app.bsky.feed.getRepostedBy' => ['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'repostedBy' => [['did' => 'did:plc:cy', 'handle' => 'cy.test']]],
			'app.bsky.feed.getQuotes' => ['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'posts' => [['uri' => 'at://q/1'], ['uri' => 'at://q/2']]],
		});
		$actors = $this->createMock(BlueskyActorService::class);
		$actors->method('cached')->willReturnCallback(static fn (string $did): ?Person => $did === 'did:plc:ann' ? new Person() : null);
		$actors->expects($this->exactly(2))->method('store');
		$store = $this->createMock(PostStore::class);
		$store->method('storePost')->willReturnCallback(static fn (array $post): bool => $post['uri'] === 'at://q/1');
		$store->expects($this->once())->method('refreshKnownPostCountsFromView')->with(['uri' => 'at://q/2'])->willReturn(true);
		$mapper = $this->createMock(ActorMapper::class);
		$mapper->method('person')->willReturn(new Person());
		$source = new BlueskyInteractionSource($config, $appView, $refs, $store, $mapper, $actors, $this->createMock(LabelerService::class), new NullLogger(), $this->createMock(DurableCache::class));
		$post = (new Note())->setId('https://social.test/@alice/1');

		$this->assertTrue($source->supports($post));
		$this->assertSame(['https://bsky.app/profile/did:plc:ann', 'https://bsky.app/profile/did:plc:ben'], $source->actors($post, 'Like', 10));
		$this->assertSame(['https://bsky.app/profile/did:plc:cy'], $source->actors($post, 'Announce', 10));
		$this->assertSame(1, $source->quotes($post, 10));
	}

	public function testVisibleReactionAndQuoteReadsPassTheirBoundedTimeout(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->exactly(2))->method('query')->willReturnCallback(
			function (string $method, array $params = [], array $headers = [], ?int $timeout = null): array {
				$this->assertGreaterThanOrEqual(1, $timeout);
				$this->assertLessThanOrEqual(3, $timeout);

				return $method === 'app.bsky.feed.getLikes'
					? ['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'likes' => []]
					: ['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'posts' => []];
			}
		);
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$this->createMock(PostStore::class),
			$this->createMock(ActorMapper::class),
			$this->createMock(BlueskyActorService::class),
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$this->createMock(DurableCache::class),
		);
		$post = (new Note())->setId('https://social.test/@alice/1');

		$this->assertSame([], $source->actors($post, 'Like', 12, 3));
		$this->assertSame(0, $source->quotes($post, 12, 3));
	}

	public function testVisibleQuoteReadLeavesLaterPagesForTheBackgroundFill(): void {
		$postUri = 'at://did:plc:alice/app.bsky.feed.post/3k';
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => $postUri, 'cid' => 'bafy']);
		$asked = [];
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->exactly(2))->method('query')->willReturnCallback(
			static function (string $method, array $params, array $headers = [], ?int $timeout = null) use (&$asked, $postUri): array {
				$asked[] = [$method, $params, $timeout];
				if ($method !== 'app.bsky.feed.getQuotes' || $params['uri'] !== $postUri) {
					throw new \UnexpectedValueException('Unexpected quote query');
				}

				return ($params['cursor'] ?? '') === ''
					? ['uri' => $postUri, 'posts' => [['uri' => 'at://did:plc:ann/app.bsky.feed.post/1']], 'cursor' => 'next-page']
					: ['uri' => $postUri, 'posts' => [['uri' => 'at://did:plc:ben/app.bsky.feed.post/2']]];
			}
		);
		$store = $this->createMock(PostStore::class);
		$store->expects($this->exactly(2))->method('storePost')->willReturn(true);
		$cursor = null;
		$cache = $this->createMock(DurableCache::class);
		$cache->method('getShared')->willReturnCallback(static function () use (&$cursor): ?string {
			return $cursor;
		});
		$cache->method('setShared')->willReturnCallback(static function (string $name, string $key, mixed $value) use (&$cursor): void {
			$cursor = $value;
		});
		$cache->method('removeShared')->willReturnCallback(static function () use (&$cursor): void {
			$cursor = null;
		});
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$store,
			$this->createMock(ActorMapper::class),
			$this->createMock(BlueskyActorService::class),
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$cache,
		);
		$post = (new Note())->setId('https://social.test/@alice/1');

		$this->assertSame(1, $source->quotes($post, 12, 3));
		$this->assertTrue($source->hasMoreQuotes($post));
		$this->assertSame(1, $source->quotes($post, 12));
		$this->assertFalse($source->hasMoreQuotes($post));
		$this->assertSame('', $asked[0][1]['cursor'] ?? '');
		$this->assertSame('next-page', $asked[1][1]['cursor'] ?? null);
		$this->assertGreaterThanOrEqual(1, $asked[0][2]);
		$this->assertLessThanOrEqual(3, $asked[0][2]);
		$this->assertNull($asked[1][2]);
	}

	public function testMissingReactionListsAreTreatedAsFailedReads(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k']);
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$this->createMock(PostStore::class),
			$this->createMock(ActorMapper::class),
			$this->createMock(BlueskyActorService::class),
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$this->createMock(DurableCache::class),
		);
		$post = (new Note())->setId('https://social.test/@alice/1');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('no likes list');
		$source->actors($post, 'Like', 10);
	}

	public function testReactionListsForAnotherPostAreRejected(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturn([
			'uri' => 'at://did:plc:alice/app.bsky.feed.post/another',
			'likes' => [],
		]);
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$this->createMock(PostStore::class),
			$this->createMock(ActorMapper::class),
			$this->createMock(BlueskyActorService::class),
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$this->createMock(DurableCache::class),
		);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('names a different post');
		$source->actors((new Note())->setId('https://social.test/@alice/1'), 'Like', 10);
	}

	public function testMissingQuoteListsAreTreatedAsFailedReads(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k']);
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$this->createMock(PostStore::class),
			$this->createMock(ActorMapper::class),
			$this->createMock(BlueskyActorService::class),
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$this->createMock(DurableCache::class),
		);
		$post = (new Note())->setId('https://social.test/@alice/1');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('no valid target or posts list');
		$source->quotes($post, 10);
	}

	public function testMalformedReactionEntriesAreTreatedAsFailedReads(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'likes' => [null]]);
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$this->createMock(PostStore::class),
			$this->createMock(ActorMapper::class),
			$this->createMock(BlueskyActorService::class),
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$this->createMock(DurableCache::class),
		);
		$post = (new Note())->setId('https://social.test/@alice/1');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('contains an invalid entry');
		$source->actors($post, 'Like', 10);
	}

	public function testMalformedQuoteEntriesAreTreatedAsFailedReads(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturn(['uri' => 'at://did:plc:alice/app.bsky.feed.post/3k', 'posts' => [null]]);
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$this->createMock(PostStore::class),
			$this->createMock(ActorMapper::class),
			$this->createMock(BlueskyActorService::class),
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$this->createMock(DurableCache::class),
		);
		$post = (new Note())->setId('https://social.test/@alice/1');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('contains an invalid post');
		$source->quotes($post, 10);
	}

	public function testQuotesFollowAppViewCursorsAndKeepProgressForTheNextCall(): void {
		$postUri = 'at://did:plc:alice/app.bsky.feed.post/3k';
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => $postUri, 'cid' => 'bafy']);
		$asked = [];
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->exactly(2))->method('query')->willReturnCallback(
			static function (string $method, array $params) use (&$asked, $postUri): array {
				$asked[] = $params;
				if ($method !== 'app.bsky.feed.getQuotes' || $params['uri'] !== $postUri) {
					throw new \UnexpectedValueException('Unexpected quote query');
				}

				return ($params['cursor'] ?? '') === ''
					? ['uri' => $postUri, 'posts' => [['uri' => 'at://did:plc:ann/app.bsky.feed.post/1']], 'cursor' => 'next-page']
					: ['uri' => $postUri, 'posts' => [['uri' => 'at://did:plc:ben/app.bsky.feed.post/2']]];
			}
		);
		$store = $this->createMock(PostStore::class);
		$store->expects($this->exactly(2))->method('storePost')->willReturn(true);
		$key = hash('sha256', $postUri);
		$cache = $this->createMock(DurableCache::class);
		$cache->expects($this->once())->method('getShared')->with('social.atproto.quotes.cursor', $key)->willReturn(null);
		$cache->expects($this->once())->method('setShared')->with('social.atproto.quotes.cursor', $key, 'next-page', 3600);
		$cache->expects($this->once())->method('removeShared')->with('social.atproto.quotes.cursor', $key);
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$store,
			$this->createMock(ActorMapper::class),
			$this->createMock(BlueskyActorService::class),
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$cache,
		);

		$this->assertSame(2, $source->quotes((new Note())->setId('https://social.test/@alice/1'), 10));
		$this->assertSame('', $asked[0]['cursor'] ?? '');
		$this->assertSame('next-page', $asked[1]['cursor'] ?? null);
	}

	public function testQuoteReadResumesFromTheStoredCursor(): void {
		$postUri = 'at://did:plc:alice/app.bsky.feed.post/3k';
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$refs = $this->createMock(PostRefs::class);
		$refs->method('strongRef')->willReturn(['uri' => $postUri, 'cid' => 'bafy']);
		$appView = $this->createMock(AppViewClient::class);
		$appView->expects($this->once())->method('query')->with('app.bsky.feed.getQuotes', [
			'uri' => $postUri,
			'limit' => 10,
			'cursor' => 'saved-page',
		], $this->anything())->willReturn(['uri' => $postUri, 'posts' => [['uri' => 'at://did:plc:ben/app.bsky.feed.post/2']]]);
		$store = $this->createMock(PostStore::class);
		$store->expects($this->once())->method('storePost')->willReturn(true);
		$cache = $this->createMock(DurableCache::class);
		$cache->expects($this->once())->method('getShared')->willReturn('saved-page');
		$cache->expects($this->once())->method('removeShared');
		$source = new BlueskyInteractionSource(
			$config,
			$appView,
			$refs,
			$store,
			$this->createMock(ActorMapper::class),
			$this->createMock(BlueskyActorService::class),
			$this->createMock(LabelerService::class),
			new NullLogger(),
			$cache,
		);

		$this->assertSame(1, $source->quotes((new Note())->setId('https://social.test/@alice/1'), 10));
	}
}
