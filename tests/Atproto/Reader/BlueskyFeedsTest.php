<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use InvalidArgumentException;
use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Client\Preferences;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Reader\BlueskyBlockedBy;
use OCA\Social\Atproto\Reader\BlueskyFeeds;
use OCA\Social\Atproto\Reader\BlueskyIds;
use OCA\Social\Atproto\Reader\PostStore;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\DurableCache;
use OCA\Social\Tests\Helper\InMemoryDurableCacheRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyFeedsTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';
	private const FEED = 'at://' . self::BOB . '/app.bsky.feed.generator/cats';
	private const LIST = 'at://' . self::BOB . '/app.bsky.graph.list/3kfriends';

	private Person $alice;
	private ?Identity $identity;
	/** @var array<string, string> the user's stored settings */
	private array $stored = [];
	/** @var array<string, mixed> */
	private array $cached = [];
	/** @var list<array{string, array, bool}> what the AppView was asked, and whether as the person */
	private array $asked = [];
	/** @var list<?int> timeout supplied for each AppView request */
	private array $askedTimeouts = [];
	/** @var array<string, array> the AppView's answers, by method */
	private array $answers = [];
	/** @var array<string, list<array>> queued AppView pages, by method */
	private array $answerPages = [];
	private int $firstAuthorFeedDelayMicros = 0;
	private int $firstCustomFeedDelayMicros = 0;
	/** @var string[] posts hidden from the viewer */
	private array $hidden = [];
	private ?BlueskyBlockedBy $blockedBy = null;
	private InMemoryDurableCacheRequest $cursorTable;
	private int $now = 1791453600;

	protected function setUp(): void {
		$this->cursorTable = new InMemoryDurableCacheRequest();
		$this->alice = new Person();
		$this->alice->setId('https://social.test/@alice');
		$this->alice->setUserId('alice');
		$this->identity = new Identity(1, $this->alice->getId(), self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0);
		$this->answers = [
			'app.bsky.feed.getFeedGenerators' => ['feeds' => [['uri' => self::FEED, 'displayName' => 'Cats', 'description' => 'Only cats', 'avatar' => 'https://cdn.test/cats.jpg', 'creator' => ['handle' => 'bob.test']]]],
			'app.bsky.graph.getList' => ['list' => ['uri' => self::LIST, 'name' => 'Friends', 'creator' => ['handle' => 'bob.test']], 'items' => []],
			'com.atproto.identity.resolveHandle' => ['did' => self::BOB],
		];
	}

	private function feeds(bool $memcache = true, ?ILockingProvider $locks = null): BlueskyFeeds {
		$appView = $this->createMock(AppViewClient::class);
		$answer = function (string $method, array $params, bool $asPerson): array {
			$this->asked[] = [$method, $params, $asPerson];
			if (in_array($method, ['app.bsky.feed.getFeed', 'app.bsky.feed.getListFeed'], true) && count(array_filter($this->asked, static fn (array $request): bool => $request[0] === $method)) === 1 && $this->firstCustomFeedDelayMicros > 0) {
				usleep($this->firstCustomFeedDelayMicros);
			}
			if ($method === 'app.bsky.feed.getAuthorFeed' && count(array_filter($this->asked, static fn (array $request): bool => $request[0] === $method)) === 1 && $this->firstAuthorFeedDelayMicros > 0) {
				usleep($this->firstAuthorFeedDelayMicros);
			}
			if (($this->answerPages[$method] ?? []) !== []) {
				return array_shift($this->answerPages[$method]);
			}

			return $this->answers[$method] ?? throw new \RuntimeException('not answered: ' . $method);
		};
		$appView->method('query')->willReturnCallback(function (string $method, array $params = [], array $headers = [], ?int $timeout = null) use ($answer): array {
			$this->askedTimeouts[] = $timeout;

			return $answer($method, $params, false);
		});
		$appView->method('queryAs')->willReturnCallback(function (string $did, PrivateKey $key, string $method, array $params = [], ?int $timeout = null) use ($answer): array {
			$this->askedTimeouts[] = $timeout;

			return $answer($method, $params, true);
		});
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(fn (): ?Identity => $this->identity);
		$identities->method('activeForActor')->willReturnCallback(fn (): ?Identity => $this->identity?->isActive() ? $this->identity : null);
		$identities->method('signingKey')->willReturn(PrivateKey::generate(Curve::K256));
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(fn (string $user, string $app, string $key, $default = ''): string => $this->stored[$key] ?? $default);
		$config->method('setUserValue')->willReturnCallback(function (string $user, string $app, string $key, $value): void {
			$this->stored[$key] = $value;
		});
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturnCallback(function (string $id): Note {
			if (in_array($id, $this->hidden, true)) {
				throw new StreamNotFoundException();
			}
			$note = new Note();
			$note->setId($id);
			$note->setNid(crc32($id));

			return $note;
		});
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cached[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->cached[$key] = $value;

			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn($memcache);
		if ($memcache) {
			$cacheFactory->method('createDistributed')->willReturn($cache);
		} else {
			$cacheFactory->expects($this->never())->method('createDistributed');
		}
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$cursors = new DurableCache($cacheFactory, $this->cursorTable, $time);

		return new BlueskyFeeds($appView, $identities, new Preferences($config, $this->createMock(\OCA\Social\Atproto\Client\MutedWords::class), $this->createMock(\OCA\Social\Service\AccountService::class)), $this->createMock(PostStore::class), $streams, $cursors, $locks ?? $this->createStub(ILockingProvider::class), new NullLogger(), $this->blockedBy);
	}

	/** @return list<array> the saved-feeds items as stored */
	private function savedItems(): array {
		foreach (json_decode($this->stored['atproto_preferences'] ?? '[]', true) as $preference) {
			if ($preference['$type'] === BlueskyFeeds::SAVED) {
				return $preference['items'];
			}
		}

		return [];
	}

	public function testOnlyFeedsAndListsAreNamedSo(): void {
		$this->assertSame(BlueskyFeeds::FEED, BlueskyFeeds::typeOf(self::FEED));
		$this->assertSame(BlueskyFeeds::LIST, BlueskyFeeds::typeOf(self::LIST));
		$this->assertNull(BlueskyFeeds::typeOf('at://' . self::BOB . '/app.bsky.feed.post/3kpost'));
		$this->assertNull(BlueskyFeeds::typeOf('at://bob.test/app.bsky.feed.generator/cats'), 'by DID only');
		$this->assertNull(BlueskyFeeds::typeOf('https://bsky.app/profile/bob.test/feed/cats'));
	}

	public function testAFeedIsKeptInTheBlueskyPreferenceByItsAddressOnce(): void {
		$this->stored['atproto_preferences'] = json_encode([['$type' => 'app.bsky.actor.defs#adultContentPref', 'enabled' => false]]);

		$added = $this->feeds()->add($this->alice, 'https://bsky.app/profile/bob.test/feed/cats');
		$this->feeds()->add($this->alice, self::FEED);

		$this->assertSame(['uri' => self::FEED, 'type' => 'feed', 'name' => 'Cats', 'description' => 'Only cats', 'avatar' => 'https://cdn.test/cats.jpg', 'creator' => 'bob.test', 'pinned' => true], $added);
		$items = $this->savedItems();
		$this->assertCount(1, $items, 'kept once');
		$this->assertSame(['type' => 'feed', 'value' => self::FEED, 'pinned' => true], array_diff_key($items[0], ['id' => 1]));
		$this->assertStringContainsString('adultContentPref', $this->stored['atproto_preferences'], 'the other preferences as they were');
	}

	public function testWhatIsNotAFeedOrListBlueskyKnowsIsNotKept(): void {
		foreach (['https://example.com/feed/cats', 'at://' . self::BOB . '/app.bsky.feed.generator/dogs'] as $typed) {
			try {
				$this->feeds()->add($this->alice, $typed);
				$this->fail($typed);
			} catch (InvalidArgumentException) {
			}
		}
		$this->assertSame([], $this->savedItems());
	}

	public function testTheSavedOnesArePinnedFirstAndOnesBlueskyForgotAreLeftOut(): void {
		$this->stored['atproto_preferences'] = json_encode([['$type' => BlueskyFeeds::SAVED, 'items' => [
			['type' => 'timeline', 'value' => 'following', 'pinned' => true, 'id' => 'a'],
			['type' => 'list', 'value' => self::LIST, 'pinned' => false, 'id' => 'b'],
			['type' => 'feed', 'value' => 'at://' . self::BOB . '/app.bsky.feed.generator/gone', 'pinned' => true, 'id' => 'c'],
			['type' => 'feed', 'value' => self::FEED, 'pinned' => true, 'id' => 'd'],
		]]]);

		$saved = $this->feeds()->saved($this->alice);

		$this->assertSame([[self::FEED, true], [self::LIST, false]], array_map(static fn (array $one): array => [$one['uri'], $one['pinned']], $saved));
	}

	public function testRemovingOneKeepsTheRest(): void {
		$this->stored['atproto_preferences'] = json_encode([['$type' => BlueskyFeeds::SAVED, 'items' => [
			['type' => 'timeline', 'value' => 'following', 'pinned' => true, 'id' => 'a'],
			['type' => 'feed', 'value' => self::FEED, 'pinned' => true, 'id' => 'd'],
		]]]);

		$this->feeds()->remove($this->alice, self::FEED);

		$this->assertSame([['type' => 'timeline', 'value' => 'following', 'pinned' => true, 'id' => 'a']], $this->savedItems());
	}

	public static function backends(): iterable {
		yield 'memcache' => [true];
		yield 'database fallback' => [false];
	}

	#[DataProvider('backends')]
	public function testAFeedIsReadAsThePersonPageByPageInItsOwnOrder(bool $memcache): void {
		$uri = static fn (string $rkey): string => 'at://' . self::BOB . '/app.bsky.feed.post/' . $rkey;
		$this->answers['app.bsky.feed.getFeed'] = ['feed' => [['post' => ['uri' => $uri('3kb')]], ['post' => ['uri' => $uri('3ka')]], ['post' => ['uri' => $uri('3kc')]]], 'cursor' => 'next-1'];
		$this->hidden = [BlueskyIds::postIdOfUri($uri('3kc'))];

		$first = $this->feeds($memcache)->page($this->alice, self::FEED, '', 2);

		$this->assertSame([BlueskyIds::postIdOfUri($uri('3kb')), BlueskyIds::postIdOfUri($uri('3ka'))], array_map(static fn (Note $note): string => $note->getId(), $first), 'its order; a hidden post left out');
		$this->assertSame(['app.bsky.feed.getFeed', ['feed' => self::FEED, 'limit' => 2], true], $this->asked[0]);

		$this->answers['app.bsky.feed.getFeed'] = ['feed' => []];
		$this->feeds($memcache)->page($this->alice, self::FEED, (string)end($first)->getNid(), 2);
		$this->assertSame(['feed' => self::FEED, 'limit' => 2, 'cursor' => 'next-1'], $this->asked[1][1], 'the page after the last post');

		$this->assertSame([], $this->feeds($memcache)->page($this->alice, self::FEED, '12345', 2), 'past the end');
		$this->assertCount(2, $this->asked, 'without asking again');
	}

	public function testUnwatchedProfileFeedUsesItsAppViewCursorAsTheLinkedViewer(): void {
		$actor = (new Person())->setId(BlueskyIds::actorId(self::BOB));
		$firstPage = [];
		$secondPage = [];
		for ($index = 0; $index < 21; $index++) {
			$firstPage[] = ['post' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/profile-post-' . $index]];
			$secondPage[] = ['post' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/profile-older-' . $index]];
		}
		$uri = 'at://' . self::BOB . '/app.bsky.feed.post/profile-post-20';
		$this->answerPages['app.bsky.feed.getAuthorFeed'] = [
			['feed' => $firstPage, 'cursor' => 'profile-next'],
			['feed' => $secondPage, 'cursor' => 'profile-end'],
		];
		$feeds = $this->feeds(false);

		self::assertFalse($feeds->authorPage($actor, '', 20, $this->alice));
		$after = (string)crc32(BlueskyIds::postIdOfUri($uri));
		self::assertFalse($feeds->authorPage($actor, $after, 20, $this->alice));

		self::assertSame(['app.bsky.feed.getAuthorFeed', [
			'actor' => self::BOB,
			'filter' => 'posts_with_replies',
			'limit' => 21,
		], true], $this->asked[0]);
		self::assertSame(['app.bsky.feed.getAuthorFeed', [
			'actor' => self::BOB,
			'filter' => 'posts_with_replies',
			'limit' => 21,
			'cursor' => 'profile-next',
		], true], $this->asked[1]);

		self::assertFalse($feeds->authorPage($actor, $after, 20, $this->alice));
		self::assertCount(2, $this->asked, 'a consumed AppView page is not fetched again while local max_id pages through its posts');
	}

	public function testProfileFeedUsesThePublicAppViewWhenTheViewerHasNoLinkedIdentity(): void {
		$actor = (new Person())->setId(BlueskyIds::actorId(self::BOB));
		$this->identity = null;
		$this->answerPages['app.bsky.feed.getAuthorFeed'] = [['feed' => []]];

		self::assertFalse($this->feeds(false)->authorPage($actor, '', 20, $this->alice));
		self::assertSame(['app.bsky.feed.getAuthorFeed', [
			'actor' => self::BOB,
			'filter' => 'posts_with_replies',
			'limit' => 21,
		], false], $this->asked[0]);
	}

	public function testAProfileWithHiddenPostsContinuesInBoundedRefillsUntilItHasAFullPage(): void {
		$actor = (new Person())->setId(BlueskyIds::actorId(self::BOB));
		$hiddenPages = [];
		for ($page = 1; $page <= 2; $page++) {
			$feed = [];
			for ($index = 0; $index < 21; $index++) {
				$uri = 'at://' . self::BOB . '/app.bsky.feed.post/hidden-' . $page . '-' . $index;
				$feed[] = ['post' => ['uri' => $uri]];
				$this->hidden[] = BlueskyIds::postIdOfUri($uri);
			}
			$hiddenPages[] = ['feed' => $feed, 'cursor' => 'profile-hidden-' . ($page + 1)];
		}
		$visible = [];
		for ($index = 0; $index < 21; $index++) {
			$visible[] = ['post' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/visible-' . $index]];
		}
		$this->answerPages['app.bsky.feed.getAuthorFeed'] = [
			...$hiddenPages,
			['feed' => $visible, 'cursor' => 'profile-after-visible'],
		];
		$feeds = $this->feeds(false);

		self::assertTrue($feeds->authorPage($actor, '', 20, $this->alice), 'the first request stops after two AppView pages and asks the client to continue');
		self::assertFalse($feeds->authorPage($actor, '', 20, $this->alice), 'the refill reads the saved cursor and completes the visible page');
		self::assertSame('profile-hidden-3', $this->asked[2][1]['cursor']);
		self::assertCount(3, $this->asked, 'the refill consumes one subsequent AppView page');
	}

	public function testProfileRefillSharesOneInteractiveTimeoutBudgetAcrossAppViewPages(): void {
		$actor = (new Person())->setId(BlueskyIds::actorId(self::BOB));
		$hidden = [];
		$visible = [];
		for ($index = 0; $index < 21; $index++) {
			$hiddenUri = 'at://' . self::BOB . '/app.bsky.feed.post/slow-hidden-' . $index;
			$hidden[] = ['post' => ['uri' => $hiddenUri]];
			$this->hidden[] = BlueskyIds::postIdOfUri($hiddenUri);
			$visible[] = ['post' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/after-slow-page-' . $index]];
		}
		$this->answerPages['app.bsky.feed.getAuthorFeed'] = [
			['feed' => $hidden, 'cursor' => 'after-slow-hidden-page'],
			['feed' => $visible, 'cursor' => 'after-visible-page'],
		];
		$this->firstAuthorFeedDelayMicros = 1_200_000;
		$feeds = $this->feeds(false);

		self::assertFalse($feeds->authorPage($actor, '', 20, $this->alice));

		self::assertSame(AppViewClient::INTERACTIVE_TIMEOUT, $this->askedTimeouts[0]);
		self::assertLessThan(AppViewClient::INTERACTIVE_TIMEOUT, $this->askedTimeouts[1], 'the next AppView page gets only the remaining time');
		self::assertGreaterThan(0, $this->askedTimeouts[1]);
	}

	public function testATemporaryAuthorFeedFailureRequestsARefillInsteadOfLookingLikeAnEmptyProfile(): void {
		$actor = (new Person())->setId(BlueskyIds::actorId(self::BOB));

		self::assertTrue($this->feeds()->authorPage($actor, '', 20, $this->alice));
	}

	public function testAConcurrentProfilePageRequestWaitsForTheCurrentCursorRead(): void {
		$actor = (new Person())->setId(BlueskyIds::actorId(self::BOB));
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects(self::once())
			->method('acquireLock')
			->with(self::callback(static fn (string $key): bool => str_starts_with($key, 'social-atproto-profile-page-')), ILockingProvider::LOCK_EXCLUSIVE)
			->willThrowException(new LockedException('another profile page request is active'));

		self::assertTrue($this->feeds(false, $locks)->authorPage($actor, '', 20, $this->alice));
		self::assertSame([], $this->asked, 'a concurrent request does not repeat the AppView fetch');
	}

	public function testAProfileRefillResumesAfterHiddenPagesWhenTheNextPageHasAnInvalidCursor(): void {
		$actor = (new Person())->setId(BlueskyIds::actorId(self::BOB));
		$hidden = [];
		$visible = [];
		for ($index = 0; $index < 21; $index++) {
			$hiddenUri = 'at://' . self::BOB . '/app.bsky.feed.post/hidden-before-error-' . $index;
			$hidden[] = ['post' => ['uri' => $hiddenUri]];
			$this->hidden[] = BlueskyIds::postIdOfUri($hiddenUri);
			$visible[] = ['post' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/visible-after-error-' . $index]];
		}
		$this->answerPages['app.bsky.feed.getAuthorFeed'] = [
			['feed' => $hidden, 'cursor' => 'after-hidden-page'],
			['feed' => [], 'cursor' => 'after-hidden-page'], // repeated cursor: invalid continuation response
			['feed' => $visible, 'cursor' => 'after-visible-page'],
		];
		$feeds = $this->feeds(false);

		self::assertTrue($feeds->authorPage($actor, '', 20, $this->alice));
		self::assertFalse($feeds->authorPage($actor, '', 20, $this->alice));
		self::assertSame('after-hidden-page', $this->asked[1][1]['cursor']);
		self::assertSame('after-hidden-page', $this->asked[2][1]['cursor'], 'the retry starts after the hidden page already read');
	}

	public function testAProfileRefillForALaterPageResumesFromItsOwnCursor(): void {
		$actor = (new Person())->setId(BlueskyIds::actorId(self::BOB));
		$firstPage = [];
		for ($index = 0; $index < 21; $index++) {
			$firstPage[] = ['post' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/page-one-' . $index]];
		}
		$hiddenPages = [];
		for ($page = 2; $page <= 3; $page++) {
			$feed = [];
			for ($index = 0; $index < 21; $index++) {
				$uri = 'at://' . self::BOB . '/app.bsky.feed.post/page-' . $page . '-hidden-' . $index;
				$feed[] = ['post' => ['uri' => $uri]];
				$this->hidden[] = BlueskyIds::postIdOfUri($uri);
			}
			$hiddenPages[] = ['feed' => $feed, 'cursor' => 'profile-page-' . ($page + 1)];
		}
		$lastOnFirstPage = BlueskyIds::postIdOfUri('at://' . self::BOB . '/app.bsky.feed.post/page-one-20');
		$finalPage = [];
		for ($index = 0; $index < 21; $index++) {
			$finalPage[] = ['post' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/page-four-' . $index]];
		}
		$this->answerPages['app.bsky.feed.getAuthorFeed'] = [
			['feed' => $firstPage, 'cursor' => 'profile-page-2'],
			...$hiddenPages,
			['feed' => $finalPage, 'cursor' => 'profile-page-5'],
		];
		$feeds = $this->feeds(false);

		self::assertFalse($feeds->authorPage($actor, '', 20, $this->alice));
		$after = (string)crc32($lastOnFirstPage);
		self::assertTrue($feeds->authorPage($actor, $after, 20, $this->alice));
		self::assertFalse($feeds->authorPage($actor, $after, 20, $this->alice));
		self::assertSame('profile-page-2', $this->asked[1][1]['cursor']);
		self::assertSame('profile-page-4', $this->asked[3][1]['cursor'], 'the refill resumes after the two pages consumed by the previous request');
		self::assertCount(4, $this->asked);
	}

	public function testEmptyPagesWithCursorsAreSkippedUntilAVisiblePostAppears(): void {
		$hiddenUri = 'at://' . self::BOB . '/app.bsky.feed.post/3kh';
		$visibleUri = 'at://' . self::BOB . '/app.bsky.feed.post/3kv';
		$this->hidden = [BlueskyIds::postIdOfUri($hiddenUri)];
		$this->answerPages['app.bsky.feed.getFeed'] = [
			['feed' => [['post' => ['uri' => $hiddenUri]]], 'cursor' => 'next-hidden'],
			['feed' => [['post' => ['uri' => $visibleUri]]], 'cursor' => 'next-visible'],
		];

		$page = $this->feeds(false)->page($this->alice, self::FEED, '', 20);

		self::assertSame([BlueskyIds::postIdOfUri($visibleUri)], array_map(static fn (Note $note): string => $note->getId(), $page));
		self::assertSame(['feed' => self::FEED, 'limit' => 20, 'cursor' => 'next-hidden'], $this->asked[1][1]);
		self::assertCount(2, $this->asked);
	}

	public function testCustomFeedRefillSharesInteractiveBudgetAndResumesOnNextRequest(): void {
		$hiddenUri = 'at://' . self::BOB . '/app.bsky.feed.post/slow-hidden';
		$visibleUri = 'at://' . self::BOB . '/app.bsky.feed.post/after-slow-page';
		$this->hidden = [BlueskyIds::postIdOfUri($hiddenUri)];
		$this->answerPages['app.bsky.feed.getFeed'] = [
			['feed' => [['post' => ['uri' => $hiddenUri]]], 'cursor' => 'after-hidden'],
			['feed' => [['post' => ['uri' => $visibleUri]]], 'cursor' => 'after-visible'],
		];
		$this->firstCustomFeedDelayMicros = 2_100_000;
		$feeds = $this->feeds(false);

		self::assertSame([], $feeds->page($this->alice, self::FEED, '', 20));
		self::assertTrue($feeds->hasFeedPagePending());
		self::assertSame(AppViewClient::INTERACTIVE_TIMEOUT, $this->askedTimeouts[0]);

		$page = $feeds->page($this->alice, self::FEED, '', 20);

		self::assertFalse($feeds->hasFeedPagePending());
		self::assertSame([BlueskyIds::postIdOfUri($visibleUri)], array_map(static fn (Note $note): string => $note->getId(), $page));
		self::assertSame('after-hidden', $this->asked[1][1]['cursor'], 'the next request resumes after the hidden page already consumed');
		self::assertSame(AppViewClient::INTERACTIVE_TIMEOUT, $this->askedTimeouts[1]);
	}

	public function testLongRunsOfEmptyPagesKeepACursorAndAskTheTimelineToContinue(): void {
		$pages = [];
		for ($i = 1; $i <= 10; $i++) {
			$pages[] = ['feed' => [], 'cursor' => 'empty-' . $i];
		}
		$visibleUri = 'at://' . self::BOB . '/app.bsky.feed.post/3kv';
		$pages[] = ['feed' => [['post' => ['uri' => $visibleUri]]]];
		$this->answerPages['app.bsky.feed.getFeed'] = $pages;
		$feeds = $this->feeds(false);

		self::assertSame([], $feeds->page($this->alice, self::FEED, '', 20));
		self::assertTrue($feeds->hasFeedPagePending());

		$page = $feeds->page($this->alice, self::FEED, '', 20);

		self::assertFalse($feeds->hasFeedPagePending());
		self::assertSame([BlueskyIds::postIdOfUri($visibleUri)], array_map(static fn (Note $note): string => $note->getId(), $page));
		self::assertSame(['feed' => self::FEED, 'limit' => 20, 'cursor' => 'empty-10'], $this->asked[10][1]);
		self::assertCount(11, $this->asked);
	}

	public function testARepeatedCursorFailsInsteadOfLoopingOrReportingTheEnd(): void {
		$hiddenUri = 'at://' . self::BOB . '/app.bsky.feed.post/3kh';
		$this->hidden = [BlueskyIds::postIdOfUri($hiddenUri)];
		$this->answerPages['app.bsky.feed.getFeed'] = [
			['feed' => [['post' => ['uri' => $hiddenUri]]], 'cursor' => 'same-cursor'],
			['feed' => [['post' => ['uri' => $hiddenUri]]], 'cursor' => 'same-cursor'],
		];

		try {
			$this->feeds()->page($this->alice, self::FEED, '', 20);
			self::fail('A repeated AppView cursor must not be treated as the end of the feed');
		} catch (\UnexpectedValueException $e) {
			self::assertStringContainsString('cursor did not advance', $e->getMessage());
		}

		self::assertCount(2, $this->asked);
	}

	public function testMalformedFeedResponseDoesNotLookLikeAnEmptyFeed(): void {
		$this->answerPages['app.bsky.feed.getFeed'] = [['cursor' => 'unread']];

		$this->expectException(\UnexpectedValueException::class);
		$this->feeds()->page($this->alice, self::FEED, '', 20);
	}

	public function testNonStringCursorIsRejected(): void {
		$this->answerPages['app.bsky.feed.getFeed'] = [['feed' => [], 'cursor' => null]];

		$this->expectException(\UnexpectedValueException::class);
		$this->feeds()->page($this->alice, self::FEED, '', 20);
	}

	public function testDatabaseCursorsArePrivateToTheViewerAndFeedAndExpire(): void {
		$this->answers['app.bsky.feed.getFeed'] = ['feed' => [['post' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/3ka']]], 'cursor' => 'private-next'];
		$first = $this->feeds(false)->page($this->alice, self::FEED, '', 20);
		$after = (string)$first[0]->getNid();
		$bob = (new Person())->setId('https://social.test/@bob');
		$this->assertSame([], $this->feeds(false)->page($bob, self::FEED, $after, 20));
		$this->assertSame([], $this->feeds(false)->page($this->alice, self::LIST, $after, 20));
		$this->now += 3601;
		$this->assertSame([], $this->feeds(false)->page($this->alice, self::FEED, $after, 20));
		$this->assertCount(1, $this->asked);
	}

	public function testAListIsReadAsItsFeedAndWithoutAnIdentityAsAnyone(): void {
		$this->identity = null;
		$this->answers['app.bsky.feed.getListFeed'] = ['feed' => []];

		$this->feeds()->page($this->alice, self::LIST, '', 500);

		$this->assertSame(['app.bsky.feed.getListFeed', ['list' => self::LIST, 'limit' => 100], false], $this->asked[0]);
		$this->expectException(InvalidArgumentException::class);
		$this->feeds()->page($this->alice, 'at://' . self::BOB . '/app.bsky.feed.post/3kpost', '', 20);
	}

	public function testWhoHasBlockedThePersonIsTakenFromAFeedReadAsThem(): void {
		$answer = ['feed' => [['post' => ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/3ka', 'author' => ['did' => self::BOB, 'viewer' => ['blockedBy' => true]]]]]];
		$this->answers['app.bsky.feed.getFeed'] = $answer;
		$blockedBy = $this->createMock(BlueskyBlockedBy::class);
		$blockedBy->expects($this->once())->method('learn')->with($this->alice, $answer);
		$this->blockedBy = $blockedBy;

		$this->feeds()->page($this->alice, self::FEED, '', 20);

		$this->identity = null;
		$this->feeds()->page($this->alice, self::FEED, '', 20);
	}

	/**
	 * Switched off for Bluesky, the person still reads feeds and gets
	 * suggestions, as anyone does: nothing is asked in their name.
	 */
	public function testAPersonSwitchedOffForBlueskyReadsAsAnyone(): void {
		$this->identity = new Identity(1, $this->alice->getId(), self::DID, 'alice.social.test', 'sealed', '', '', Identity::STATE_DEACTIVATED, '', 0);
		$this->answers['app.bsky.feed.getFeed'] = ['feed' => []];
		$this->answers['app.bsky.feed.getSuggestedFeeds'] = ['feeds' => []];

		$this->feeds()->page($this->alice, self::FEED, '', 20);
		$this->feeds()->suggested($this->alice);

		$this->assertSame([['app.bsky.feed.getFeed', false], ['app.bsky.feed.getSuggestedFeeds', false]], array_map(static fn (array $asked): array => [$asked[0], $asked[2]], $this->asked));
	}
}
