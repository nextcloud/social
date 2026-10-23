<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Reader\SuggestedStarterPacks;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\StarterPacksRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CollectionPackService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Service\StarterPackModeration;
use OCA\Social\Service\StarterPackService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Reading other servers' collections (FEP-7aa9) in as starter packs, against
 * a network held in PHP: a curator on mastodon.social with collections whose
 * members live on several servers, each with an authorization of its own.
 * `$down` are addresses whose server does not answer; any other address
 * not in `$documents` answers that there is no such document.
 */
#[AllowMockObjectsWithoutExpectations]
class CollectionPackServiceTest extends TestCase {
	private const CURATOR = 'https://mastodon.social/users/Gargron';
	private const SECOND = 'https://mastodon.social/users/second';
	private const LIST = 'https://mastodon.social/ap/users/1/featured_collections';
	private const NEWS = 'https://mastodon.social/ap/users/1/collections/11';
	private const PAPER = 'https://newsie.social/users/paper';
	private const TIMES = 'https://mastodon.social/users/times';
	private const BLOG = 'https://journa.host/users/blog';

	/** @var array<string, array<string, mixed>> what the network answers, by address */
	private array $documents = [];
	/** @var array<string, array<string, mixed>> what is stored, by uri */
	private array $stored = [];
	/** @var list<int> */
	private array $deleted = [];
	private string $sources = '';
	/** @var list<string> addresses that do not answer */
	private array $down = [];
	/** @var list<string> accounts this instance's moderation keeps out */
	private array $moderated = [];
	/** @var list<list<string>> the authorizations asked for, one list per collection */
	private array $asked = [];
	/** @var list<string> the actors queued to be fetched */
	private array $queued = [];
	/** @var list<string> the actors held in the cache */
	private array $cached = [];
	/** @var list<string> the collections whose row cannot be written */
	private array $unwritable = [];
	private int $now = 1_790_000_000;
	/** Seconds the clock moves on with each batch of authorizations asked for. */
	private int $tick = 0;

	protected function setUp(): void {
		$this->documents[self::CURATOR] = ['id' => self::CURATOR, 'type' => 'Person', 'featuredCollections' => self::LIST];
		// a curator with no collection at all
		$this->documents[self::SECOND] = ['id' => self::SECOND, 'type' => 'Person'];
		$this->documents[self::LIST] = ['id' => self::LIST, 'type' => 'OrderedCollection', 'orderedItems' => [self::NEWS]];
		$this->documents[self::NEWS] = $this->collection(self::NEWS, 'News organizations of Mastodon', [
			self::PAPER => $this->authorization(self::PAPER, self::NEWS),
			self::TIMES => $this->authorization(self::TIMES, self::NEWS),
		]);
	}

	/**
	 * @param array<string, string> $members authorization by member
	 *
	 * @return array<string, mixed>
	 */
	private function collection(string $id, string $name, array $members, array $extra = []): array {
		$items = [];
		foreach ($members as $member => $authorization) {
			$items[] = ['type' => 'FeaturedItem', 'featuredObject' => $member, 'featureAuthorization' => $authorization];
		}

		return $extra + [
			'id' => $id,
			'type' => 'FeaturedCollection',
			'name' => $name,
			'summaryMap' => ['en' => '<p>Papers &amp; more</p>'],
			'attributedTo' => self::CURATOR,
			'url' => 'https://mastodon.social/collections/11',
			'topic' => ['type' => 'Hashtag', 'name' => '#news', 'href' => 'https://mastodon.social/tags/news'],
			'orderedItems' => $items,
		];
	}

	/** An authorization served on the member's server, naming member and collection. */
	private function authorization(string $member, string $collection, ?string $target = null): string {
		$host = (string)parse_url($member, PHP_URL_HOST);
		$id = 'https://' . $host . '/ap/feature_authorizations/' . md5($member . $collection);
		$this->documents[$id] = [
			'id' => $id,
			'type' => 'FeatureAuthorization',
			'interactionTarget' => $target ?? $member,
			'interactingObject' => $collection,
		];

		return $id;
	}

	private function service(?ContainerInterface $container = null): CollectionPackService {
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValue')->willReturnCallback(fn (): string => $this->sources);

		$curator = new Person();
		$curator->setId(self::CURATOR);
		$curator->setAccount('Gargron@mastodon.social');
		$actors = $this->createStub(CacheActorService::class);
		$second = new Person();
		$second->setId(self::SECOND);
		$second->setAccount('second@mastodon.social');
		$actors->method('getFromAccount')->willReturnCallback(function (string $account) use ($curator, $second): Person {
			if (in_array('account:' . $account, $this->down, true)) {
				throw new RuntimeException('not reached');
			}

			return str_starts_with($account, 'second@') ? $second : $curator;
		});
		$actors->method('getFromId')->willReturnCallback(static function (string $id) use ($curator): Person {
			if ($id === self::CURATOR) {
				return $curator;
			}
			$person = new Person();
			// the numbered address Mastodon also serves an account at
			$person->setId(str_replace('/ap/users/7', '/users/times', $id));

			return $person;
		});
		$actors->method('getCachedFromIds')->willReturnCallback(fn (array $ids): array => array_fill_keys(array_intersect($ids, $this->cached), new Person()));

		$request = $this->createStub(StarterPacksRequest::class);
		$request->method('save')->willReturnCallback(function (array $pack): bool {
			if (in_array($pack['uri'], $this->unwritable, true)) {
				throw new RuntimeException('cannot write');
			}
			// a pack read again keeps the slug it was stored with
			$this->stored[$pack['uri']] = ['slug' => $this->stored[$pack['uri']]['slug'] ?? $pack['slug']] + $pack
				+ ['id' => $this->stored[$pack['uri']]['id'] ?? count($this->stored) + 1];

			return true;
		});
		$request->method('getByUri')->willReturnCallback(fn (string $uri): ?array => $this->stored[$uri] ?? null);
		$request->method('staleOf')->willReturnCallback(fn (string $origin, array $keep): array => array_values(array_filter(
			$this->stored,
			static fn (array $pack): bool => $pack['origin'] === $origin && !in_array($pack['uri'], $keep, true)
		)));
		$request->method('deleteByIds')->willReturnCallback(function (array $ids): void {
			$this->deleted = [...$this->deleted, ...$ids];
			$this->stored = array_filter($this->stored, static fn (array $pack): bool => !in_array($pack['id'], $ids, true));
		});

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$queue = $this->createStub(RemoteFetchQueue::class);
		$queue->method('resolveActors')->willReturnCallback(function (array $ids): void {
			$this->assertLessThanOrEqual(RemoteFetchQueue::MAX_PER_CALL, count($ids));
			$this->queued = [...$this->queued, ...$ids];
		});

		$moderation = $this->createStub(StarterPackModeration::class);
		$moderation->method('admitted')->willReturnCallback(fn (array $ids): array => array_values(array_diff($ids, $this->moderated)));
		$moderation->method('admits')->willReturnCallback(fn (Person $actor): bool => !in_array($actor->getId(), $this->moderated, true));

		return new CollectionPackService($config, $actors, $this->network(), $request, $time, new NullLogger(), $queue, $moderation, $container);
	}

	/** The network as `CurlService` reads it; a double, as the many-fetch reports its failures by reference. */
	private function network(): CurlService {
		$one = function (string $id): array {
			if (!isset($this->documents[$id]) || in_array($id, $this->down, true)) {
				throw new RuntimeException('not reached: ' . $id);
			}

			return $this->documents[$id];
		};
		$many = function (array $ids, ?array &$failed): array {
			$this->asked[] = $ids;
			$this->now += $this->tick;
			$failed = array_values(array_intersect($ids, $this->down));
			$answers = [];
			foreach ($ids as $id) {
				$answers[$id] = in_array($id, $this->down, true) ? null : ($this->documents[$id] ?? null);
			}

			return $answers;
		};

		return new class($one, $many) extends CurlService {
			public function __construct(
				private \Closure $one,
				private \Closure $many,
			) {
			}

			#[\Override]
			public function retrieveObject(string $id, bool $acceptActivityJson = true, ?int $timeout = null): array {
				return ($this->one)($id);
			}

			#[\Override]
			public function retrieveObjectsMany(array $ids, array $options = [], ?array &$statuses = null, ?array &$failed = null): array {
				return ($this->many)($ids, $failed);
			}
		};
	}

	public function testACuratorsCollectionIsStoredWithTheMembersWhoseConsentHolds(): void {
		$result = $this->service()->refresh();

		$this->assertSame(['sources' => 2, 'packs' => 1, 'members' => 2, 'refused' => 0, 'failed' => 0], $result);
		$pack = $this->stored[self::NEWS];
		$this->assertSame([self::PAPER, self::TIMES], $pack['members']);
		$this->assertSame('News organizations of Mastodon', $pack['name']);
		$this->assertSame('Papers & more', $pack['summary']);
		$this->assertSame('news', $pack['topic']);
		$this->assertSame('en', $pack['language']);
		$this->assertSame('Gargron@mastodon.social', $pack['curator_acct']);
		$this->assertSame('fediverse', $pack['origin']);
		$this->assertSame('https://mastodon.social/collections/11', $pack['url']);
		$this->assertSame(CollectionPackService::slug('News organizations of Mastodon', self::NEWS), $pack['slug']);
	}

	public function testAMemberWithoutValidConsentIsLeftOut(): void {
		$this->documents[self::NEWS] = $this->collection(self::NEWS, 'News', [
			self::PAPER => $this->authorization(self::PAPER, self::NEWS),
			// naming somebody else
			self::TIMES => $this->authorization(self::TIMES, self::NEWS, self::BLOG),
			// served by the curator's server rather than the member's
			self::BLOG => 'https://mastodon.social/ap/feature_authorizations/forged',
		]);
		$this->documents['https://mastodon.social/ap/feature_authorizations/forged'] = [
			'id' => 'https://mastodon.social/ap/feature_authorizations/forged',
			'type' => 'FeatureAuthorization',
			'interactionTarget' => self::BLOG,
			'interactingObject' => self::NEWS,
		];

		$result = $this->service()->refresh();

		$this->assertSame([self::PAPER], $this->stored[self::NEWS]['members']);
		$this->assertSame(2, $result['refused']);
	}

	public function testAnAuthorizationMayNameTheMemberByItsNumberedAddress(): void {
		$this->documents[self::NEWS] = $this->collection(self::NEWS, 'News', [
			self::TIMES => $this->authorization(self::TIMES, self::NEWS, 'https://mastodon.social/ap/users/7'),
		]);

		$this->service()->refresh();

		$this->assertSame([self::TIMES], $this->stored[self::NEWS]['members']);
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function collectionsNotOffered(): array {
		return [
			'not discoverable' => [['discoverable' => false]],
			'sensitive' => [['sensitive' => true]],
			'by somebody else' => [['attributedTo' => 'https://mastodon.social/users/other']],
			'not a featured collection' => [['type' => 'OrderedCollection']],
		];
	}

	#[DataProvider('collectionsNotOffered')]
	public function testACollectionThatIsNotForNewcomersIsNotOffered(array $extra): void {
		$this->documents[self::NEWS] = $this->collection(self::NEWS, 'News', [
			self::PAPER => $this->authorization(self::PAPER, self::NEWS),
		], $extra);

		$this->service()->refresh();

		$this->assertSame([], $this->stored);
	}

	public function testACollectionTheCuratorNoLongerListsIsRemoved(): void {
		$service = $this->service();
		$service->refresh();
		$this->documents[self::LIST]['orderedItems'] = [];

		$service->refresh();

		$this->assertSame([], $this->stored);
	}

	public function testACuratorThatCannotBeReachedKeepsItsPacks(): void {
		$service = $this->service();
		$service->refresh();
		$this->down[] = self::CURATOR;

		$result = $service->refresh();

		$this->assertSame(1, $result['failed']);
		$this->assertArrayHasKey(self::NEWS, $this->stored);
	}

	public function testASourceTakenOutOfTheListTakesItsPacksWithIt(): void {
		$service = $this->service();
		$service->refresh();
		$this->sources = '["second@mastodon.social"]';
		$this->down[] = 'account:second@mastodon.social';

		$service->refresh();

		$this->assertSame([], $this->stored);
	}

	public function testAnEmptyListReadsNobody(): void {
		$this->sources = '[]';

		$this->assertSame([], $this->service()->sources());
		$this->assertSame(0, $this->service()->refresh()['packs']);
	}

	public function testTheSourcesAreShapedAndUnsetIsTheShippedList(): void {
		$this->assertSame(CollectionPackService::DEFAULT_SOURCES, $this->service()->sources());

		$this->sources = 'not json';
		$this->assertSame(CollectionPackService::DEFAULT_SOURCES, $this->service()->sources());

		$this->sources = '["@alice@example.org", "https://example.org/c/1", "http://insecure.example/c", "not a handle", "alice@example.org"]';
		$this->assertSame(['alice@example.org', 'https://example.org/c/1'], $this->service()->sources());
	}

	public function testACollectionNamedByItsAddressIsReadAlone(): void {
		$this->sources = '["' . self::NEWS . '"]';

		$this->service()->refresh();

		$this->assertSame([self::PAPER, self::TIMES], $this->stored[self::NEWS]['members']);
	}

	public function testACuratorNamedTwiceIsReadOnce(): void {
		$this->sources = '["Gargron@mastodon.social", "' . self::NEWS . '"]';

		$result = $this->service()->refresh();

		$this->assertSame(1, $result['packs']);
	}

	public function testTwoCollectionsOfOneNameAreTwoSlugs(): void {
		$one = CollectionPackService::slug('Artists of Mastodon', 'https://a.example/c/1');
		$two = CollectionPackService::slug('Artists of Mastodon', 'https://a.example/c/2');

		$this->assertMatchesRegularExpression('/^artists-of-mastodon-[0-9a-f]{16}$/', $one);
		$this->assertNotSame($one, $two);
		$this->assertMatchesRegularExpression('/^collection-[0-9a-f]{16}$/', CollectionPackService::slug('✨', 'https://a.example/c/3'));
		$this->assertLessThanOrEqual(64, strlen(CollectionPackService::slug(str_repeat('long name ', 20), 'https://a.example/c/4')), 'the slug column is 64 wide');
	}

	public function testAPackReadAgainKeepsItsSlug(): void {
		$service = $this->service();
		$service->refresh();
		$this->stored[self::NEWS]['slug'] = 'news-organizations-of-mastodon-1a2b3c4d';

		$service->refresh();

		$this->assertSame('news-organizations-of-mastodon-1a2b3c4d', $this->stored[self::NEWS]['slug']);
	}

	/** A container holding Bluesky's suggestions reader, with Bluesky on or off. */
	private function bluesky(SuggestedStarterPacks $suggested, bool $enabled): ContainerInterface {
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn($enabled);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class): ?object => match ($class) {
				AtprotoConfig::class => $config,
				SuggestedStarterPacks::class => $suggested,
				default => null,
			}
		);

		return $container;
	}

	public function testBlueskysSuggestionsAreReadWithTheCollections(): void {
		$suggested = $this->createMock(SuggestedStarterPacks::class);
		$suggested->expects($this->once())->method('refresh')->willReturn(['packs' => 3, 'members' => 40]);
		$suggested->expects($this->never())->method('forgetAllBut');

		$result = $this->service($this->bluesky($suggested, true))->refresh();

		$this->assertSame(4, $result['packs']);
		$this->assertSame(42, $result['members']);
	}

	public function testBlueskysSuggestionsGoWhenBlueskyIsOff(): void {
		$suggested = $this->createMock(SuggestedStarterPacks::class);
		$suggested->expects($this->never())->method('refresh');
		$suggested->expects($this->once())->method('forgetAllBut');

		$this->service($this->bluesky($suggested, false))->refresh();
	}

	public function testBlueskysSuggestionsGoWhenTheyAreNoLongerASource(): void {
		$this->sources = '["Gargron@mastodon.social"]';
		$suggested = $this->createMock(SuggestedStarterPacks::class);
		$suggested->expects($this->never())->method('refresh');
		$suggested->expects($this->once())->method('forgetAllBut');

		$this->service($this->bluesky($suggested, true))->refresh();
	}

	public function testAnAppViewThatDoesNotAnswerIsAFailedSource(): void {
		$suggested = $this->createStub(SuggestedStarterPacks::class);
		$suggested->method('refresh')->willThrowException(new RuntimeException('down'));

		$this->assertSame(1, $this->service($this->bluesky($suggested, true))->refresh()['failed']);
	}

	public function testTheFediversesCleanupLeavesBlueskysPacksAlone(): void {
		$this->stored['at://did:plc:a/app.bsky.graph.starterpack/1'] = [
			'id' => 99, 'uri' => 'at://did:plc:a/app.bsky.graph.starterpack/1', 'origin' => 'bluesky',
			'curator' => 'https://bsky.app/profile/did:plc:a', 'curator_acct' => 'a.bsky.social',
		];
		$this->documents[self::LIST]['orderedItems'] = [];

		$this->service()->refresh();

		$this->assertArrayHasKey('at://did:plc:a/app.bsky.graph.starterpack/1', $this->stored);
		$this->assertNotContains(99, $this->deleted);
	}

	public function testAMemberWhoseServerDoesNotAnswerStaysWhereItWasVerifiedBefore(): void {
		$service = $this->service();
		$service->refresh();
		$this->down[] = $this->documents[self::NEWS]['orderedItems'][1]['featureAuthorization'];

		$result = $service->refresh();

		$this->assertSame([self::PAPER, self::TIMES], $this->stored[self::NEWS]['members']);
		$this->assertSame(0, $result['refused']);
	}

	public function testAMemberWhoseServerDoesNotAnswerIsNotAddedUnchecked(): void {
		$this->down[] = $this->documents[self::NEWS]['orderedItems'][1]['featureAuthorization'];

		$this->service()->refresh();

		$this->assertSame([self::PAPER], $this->stored[self::NEWS]['members']);
	}

	public function testACollectionNoMemberOfWhichCouldBeCheckedKeepsItsMembers(): void {
		$service = $this->service();
		$service->refresh();
		foreach ($this->documents[self::NEWS]['orderedItems'] as $item) {
			$this->down[] = $item['featureAuthorization'];
		}

		$service->refresh();

		$this->assertSame([], $this->deleted);
		$this->assertSame([self::PAPER, self::TIMES], $this->stored[self::NEWS]['members']);
	}

	public function testACollectionWhosePageCannotBeReadKeepsWhatWasRead(): void {
		$page = 'https://mastodon.social/ap/users/1/collections/11?page=1';
		$this->documents[self::NEWS]['first'] = $page;
		$this->documents[$page] = ['id' => $page, 'type' => 'OrderedCollectionPage', 'orderedItems' => $this->documents[self::NEWS]['orderedItems']];
		unset($this->documents[self::NEWS]['orderedItems']);
		$service = $this->service();
		$service->refresh();
		$this->down[] = $page;

		$result = $service->refresh();

		$this->assertSame(1, $result['failed']);
		$this->assertSame([], $this->deleted);
		$this->assertSame([self::PAPER, self::TIMES], $this->stored[self::NEWS]['members']);
	}

	public function testANewCollectionNoMemberOfWhichCouldBeCheckedIsReadNextTime(): void {
		foreach ($this->documents[self::NEWS]['orderedItems'] as $item) {
			$this->down[] = $item['featureAuthorization'];
		}

		$result = $this->service()->refresh();

		$this->assertSame(1, $result['failed']);
		$this->assertSame(0, $result['refused']);
		$this->assertSame([], $this->stored);
	}

	public function testAMemberWhoseServerNoLongerHasTheConsentGoesThoughOthersDidNotAnswer(): void {
		$service = $this->service();
		$service->refresh();
		$items = $this->documents[self::NEWS]['orderedItems'];
		unset($this->documents[$items[0]['featureAuthorization']]);
		$this->down[] = $items[1]['featureAuthorization'];

		$result = $service->refresh();

		$this->assertSame([self::TIMES], $this->stored[self::NEWS]['members']);
		$this->assertSame(1, $result['refused']);
	}

	public function testOnlyAsManyMembersAsAPackOffersAreChecked(): void {
		$members = [];
		for ($n = 0; $n < 80; $n++) {
			$member = 'https://m' . $n . '.example/users/u';
			$members[$member] = $this->authorization($member, self::NEWS);
		}
		$this->documents[self::NEWS] = $this->collection(self::NEWS, 'Many', $members);

		$this->service()->refresh();

		$this->assertCount(StarterPackService::MAX_HANDLES, $this->asked[0]);
		$this->assertSame(array_slice(array_keys($members), 0, StarterPackService::MAX_HANDLES), $this->stored[self::NEWS]['members']);
	}

	public function testTheMembersNotCachedAreQueuedRatherThanFetched(): void {
		$this->cached = [self::PAPER];

		$this->service()->refresh();

		$this->assertSame([self::TIMES], $this->queued);
	}

	/** A second collection of the curator's, read after the first. */
	private function withASecondCollection(): string {
		$art = 'https://mastodon.social/ap/users/1/collections/12';
		$this->documents[$art] = $this->collection($art, 'Art', [self::BLOG => $this->authorization(self::BLOG, $art)]);
		$this->documents[self::LIST]['orderedItems'] = [self::NEWS, $art];

		return $art;
	}

	public function testOneCollectionThatCannotBeStoredDoesNotStopTheRest(): void {
		$art = $this->withASecondCollection();
		$this->unwritable = [self::NEWS];

		$result = $this->service()->refresh();

		$this->assertSame(1, $result['failed']);
		$this->assertSame(1, $result['packs']);
		$this->assertArrayHasKey($art, $this->stored);
	}

	public function testTheRunStopsAtItsDeadlineAndWhatItDidNotReadStays(): void {
		$art = $this->withASecondCollection();
		$service = $this->service();
		$service->refresh();
		$this->stored[$art]['name'] = 'as read last time';
		$this->asked = [];
		// each batch of authorizations takes a minute; the run has thirty seconds
		$this->tick = 60;

		$result = $service->refresh($this->now + 30);

		$this->assertCount(1, $this->asked, 'a collection was read after the deadline');
		$this->assertSame([], $this->deleted);
		$this->assertSame('as read last time', $this->stored[$art]['name']);
		$this->assertGreaterThan(0, $result['failed']);
	}

	public function testARunPastItsDeadlineReadsNothingAndRemovesNothing(): void {
		$service = $this->service();
		$service->refresh();
		$this->asked = [];

		$result = $service->refresh($this->now);

		$this->assertSame([], $this->asked);
		$this->assertArrayHasKey(self::NEWS, $this->stored);
		$this->assertSame(1, $result['failed']);
	}

	public function testAMemberThisInstanceKeepsOutIsLeftOut(): void {
		$this->moderated = [self::TIMES];

		$this->service()->refresh();

		$this->assertSame([self::PAPER], $this->stored[self::NEWS]['members']);
		$this->assertNotContains($this->documents[self::NEWS]['orderedItems'][1]['featureAuthorization'], $this->asked[0]);
	}

	public function testACuratorThisInstanceKeepsOutOffersNothing(): void {
		$service = $this->service();
		$service->refresh();
		$this->moderated = [self::CURATOR];

		$service->refresh();

		$this->assertSame([], $this->stored);
	}

	public function testBlueskysSuggestionsStayWhenTheRunIsOutOfTime(): void {
		$suggested = $this->createMock(SuggestedStarterPacks::class);
		$suggested->expects($this->never())->method('refresh');
		$suggested->expects($this->never())->method('forgetAllBut');

		$this->service($this->bluesky($suggested, true))->refresh($this->now);
	}

	public function testBlueskysSuggestionsAreASource(): void {
		$this->sources = '["bluesky:suggested", "bluesky:other"]';

		$this->assertSame(['bluesky:suggested'], $this->service()->sources());
	}
}
