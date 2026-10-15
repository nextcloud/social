<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StarterPacksRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\StarterPack;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\RemoteFetchQueue;
use OCA\Social\Service\StarterPackModeration;
use OCA\Social\Service\StarterPackService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class StarterPackServiceTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	private ConfigService|Stub $configService;
	private CacheActorService|MockObject $cacheActorService;
	private FollowService|Stub $followService;
	private StarterPackService $service;

	/** @var string[] handles held here, which resolve; anything else raises */
	private array $resolvable = [];
	/** @var string[] handles not held here, which resolve only when fetched */
	private array $remote = [];
	/** @var string[] handles looked up with a fetch allowed */
	private array $fetched = [];
	/** @var string[] what followActor was asked to follow */
	private array $followed = [];
	/** @var string[] actor ids this instance's moderation keeps out */
	private array $moderated = [];
	/** @var string[] handles and actor ids queued to be fetched */
	private array $queued = [];
	private string $configured = '';

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string
				=> ($key === StarterPackService::CONFIG_KEY) ? $this->configured : '');

		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->cacheActorService->method('getFromAccount')
			->willReturnCallback(function (string $handle, bool $retrieve = true): Person {
				if ($retrieve) {
					$this->fetched[] = $handle;
				}
				if (!in_array($handle, $this->resolvable, true) && !($retrieve && in_array($handle, $this->remote, true))) {
					throw new RuntimeException('unreachable');
				}

				return self::person($handle);
			});

		$this->followService = $this->createStub(FollowService::class);
		$this->followService->method('followActor')
			->willReturnCallback(function (Person $actor, Person $account): bool {
				$this->followed[] = $account->getAccount();

				return true;
			});

		$this->service = $this->service();
	}

	private function service(?StarterPacksRequest $request = null, ?FollowService $follows = null): StarterPackService {
		$moderation = $this->createStub(StarterPackModeration::class);
		$moderation->method('admitted')->willReturnCallback(fn (array $ids): array => array_values(array_diff($ids, $this->moderated)));
		$moderation->method('admittedActors')->willReturnCallback(fn (array $actors): array => array_values(array_filter(
			$actors, fn (Person $actor): bool => !in_array($actor->getId(), $this->moderated, true)
		)));
		$queue = $this->createStub(RemoteFetchQueue::class);
		$queue->method('resolveActors')->willReturnCallback(function (array $ids): void {
			$this->queued = [...$this->queued, ...$ids];
		});
		$queue->method('resolveAccounts')->willReturnCallback(function (array $handles): void {
			$this->queued = [...$this->queued, ...$handles];
		});

		return new StarterPackService(
			$this->configService, $this->cacheActorService, $follows ?? $this->followService, new NullLogger(), $request, $moderation, $queue
		);
	}

	private static function person(string $handle): Person {
		$person = new Person();
		$person->setId('https://' . explode('@', $handle)[1] . '/users/' . explode('@', $handle)[0]);
		$person->setAccount($handle);

		return $person;
	}

	private function viewer(): Person {
		$person = new Person();
		$person->setId(self::VIEWER);

		return $person;
	}

	public function testTheShippedPacksAreThere(): void {
		$packs = $this->service->packs();

		$this->assertNotEmpty($packs);
		foreach ($packs as $pack) {
			$this->assertSame(StarterPack::SOURCE_BUILTIN, $pack->getSource());
			$this->assertNotSame('', $pack->getName());
			$this->assertNotEmpty($pack->getHandles());
		}
	}

	/**
	 * The index is names and counts. Resolving a handle costs a WebFinger
	 * lookup and an actor fetch against another server, and doing that to draw
	 * a list of names would make the page wait on the internet for nothing.
	 */
	public function testTheIndexResolvesNobody(): void {
		$this->cacheActorService->expects($this->never())->method('getFromAccount');

		$packs = $this->service->packs();

		foreach ($packs as $pack) {
			$this->assertSame([], $pack->getAccounts());
			$this->assertGreaterThan(0, $pack->jsonSerialize()['size']);
		}
	}

	public function testOpeningAPackResolvesItsHandles(): void {
		$slug = $this->service->packs()[0]->getSlug();
		$this->resolvable = $this->service->packs()[0]->getHandles();

		$pack = $this->service->pack($slug);

		$this->assertCount(count($this->resolvable), $pack->getAccounts());
		$this->assertSame([], $pack->getUnresolved());
	}

	/**
	 * A pack that quietly shrinks looks like one somebody wrote badly, when what
	 * happened is that a server was down or an account moved.
	 */
	public function testAHandleThatWillNotResolveIsReportedNotDropped(): void {
		$first = $this->service->packs()[0];
		$this->resolvable = [$first->getHandles()[0]];

		$pack = $this->service->pack($first->getSlug());

		$this->assertCount(1, $pack->getAccounts());
		$this->assertSame(
			array_slice($first->getHandles(), 1),
			$pack->getUnresolved(),
			'the unreachable handles were dropped instead of reported'
		);
	}

	public function testOpeningAPackFetchesNothingAndQueuesWhoIsNotHeldHere(): void {
		$first = $this->service->packs()[0];
		$this->remote = $first->getHandles();

		$pack = $this->service->pack($first->getSlug());

		$this->assertSame([], $pack->getAccounts());
		$this->assertSame($first->getHandles(), $pack->getUnresolved());
		$this->assertSame([], $this->fetched);
		$this->assertSame($first->getHandles(), $this->queued);
	}

	public function testAnUnknownSlugIsANotFound(): void {
		$this->expectException(ItemNotFoundException::class);

		$this->service->pack('no-such-pack');
	}

	public function testAnAdministratorCanAddAPack(): void {
		$this->configured = json_encode([[
			'slug' => 'local-folk',
			'name' => 'People here',
			'description' => 'the ones nearby',
			'handles' => ['bob@example.org'],
		]]);

		$packs = $this->service->packs();
		$mine = array_values(array_filter($packs, fn (StarterPack $p): bool => $p->getSlug() === 'local-folk'));

		$this->assertCount(1, $mine);
		$this->assertSame(StarterPack::SOURCE_LOCAL, $mine[0]->getSource());
		$this->assertSame(['bob@example.org'], $mine[0]->getHandles());
	}

	/** A configured pack with a shipped slug edits it rather than sitting beside it. */
	public function testAConfiguredPackReplacesAShippedOneOfTheSameSlug(): void {
		$shipped = $this->service->packs()[0]->getSlug();
		$this->configured = json_encode([[
			'slug' => $shipped,
			'name' => 'Ours instead',
			'handles' => ['bob@example.org'],
		]]);

		$packs = $this->service->packs();
		$matching = array_values(array_filter($packs, fn (StarterPack $p): bool => $p->getSlug() === $shipped));

		$this->assertCount(1, $matching, 'the shipped pack was duplicated rather than replaced');
		$this->assertSame('Ours instead', $matching[0]->getName());
		$this->assertSame(StarterPack::SOURCE_LOCAL, $matching[0]->getSource());
	}

	/**
	 * Three states, deliberately distinguishable: unset is "whatever ships", an
	 * empty list is "suggest nobody", a list is "these as well".
	 */
	public function testAnEmptyConfigMeansSuggestNobody(): void {
		$this->configured = json_encode([]);

		$this->assertSame([], $this->service->packs());
	}

	public function testAnUnsetConfigMeansWhateverShips(): void {
		$this->configured = '';

		$this->assertNotEmpty($this->service->packs());
	}

	/**
	 * A typo in a config value must not be why a page is blank with no
	 * explanation.
	 */
	public function testMalformedConfigIsIgnoredRatherThanRaised(): void {
		foreach (['not json at all', '{"not":"a list"}', '[', 'null'] as $rubbish) {
			$this->configured = $rubbish;

			$this->assertNotEmpty($this->service->packs(), "rubbish config emptied the packs: $rubbish");
		}
	}

	public static function badHandleProvider(): array {
		return [
			'no host' => ['alice'],
			'no tld' => ['alice@localhost'],
			'a url' => ['https://example.org/users/alice'],
			'spaces' => ['alice @ example.org'],
			'empty' => [''],
			'injection attempt' => ['alice@example.org/../../etc/passwd'],
		];
	}

	/**
	 * These strings reach a WebFinger lookup. One that is not a handle is
	 * refused here rather than turned into a request to whatever it resembles.
	 */
	#[DataProvider('badHandleProvider')]
	public function testAHandleThatIsNotOneIsRefused(string $handle): void {
		$this->configured = json_encode([[
			'slug' => 'bad', 'name' => 'bad', 'handles' => [$handle],
		]]);

		$slugs = array_map(fn (StarterPack $p): string => $p->getSlug(), $this->service->packs());

		$this->assertNotContains('bad', $slugs, "a pack was built from the handle '$handle'");
	}

	public function testALeadingAtIsAccepted(): void {
		$this->configured = json_encode([[
			'slug' => 'ats', 'name' => 'ats', 'handles' => ['@bob@example.org'],
		]]);

		$pack = $this->service->pack('ats');

		$this->assertSame(['bob@example.org'], $pack->getHandles());
	}

	public function testADuplicateHandleIsListedOnce(): void {
		$this->configured = json_encode([[
			'slug' => 'dupes', 'name' => 'dupes',
			'handles' => ['bob@example.org', '@bob@example.org', 'bob@example.org'],
		]]);

		$this->assertSame(['bob@example.org'], $this->service->pack('dupes')->getHandles());
	}

	public function testAPackWithNoUsableHandlesIsNotAPack(): void {
		$this->configured = json_encode([['slug' => 'empty', 'name' => 'empty', 'handles' => []]]);

		$slugs = array_map(fn (StarterPack $p): string => $p->getSlug(), $this->service->packs());

		$this->assertNotContains('empty', $slugs);
	}

	// following

	public function testFollowingAPackFollowsEveryoneInIt(): void {
		$this->configured = json_encode([[
			'slug' => 'three', 'name' => 'three',
			'handles' => ['a@example.org', 'b@example.org', 'c@example.org'],
		]]);
		$this->resolvable = ['a@example.org', 'b@example.org', 'c@example.org'];

		$followed = $this->service->followAll($this->viewer(), 'three');

		$this->assertSame($this->resolvable, $followed);
		$this->assertSame($this->resolvable, $this->followed);
	}

	/** Each account is looked up once and followed as the actor found. */
	public function testFollowingAPackResolvesItOnceAndFetchesWhoIsNotHeldHere(): void {
		$this->configured = json_encode([[
			'slug' => 'two', 'name' => 'two', 'handles' => ['a@example.org', 'b@example.org'],
		]]);
		$this->resolvable = ['a@example.org'];
		$this->remote = ['b@example.org'];
		$follows = $this->createMock(FollowService::class);
		$follows->expects($this->exactly(2))->method('followActor')->willReturn(true);
		$follows->expects($this->never())->method('followAccount');

		$followed = $this->service(null, $follows)->followAll($this->viewer(), 'two');

		$this->assertSame(['a@example.org', 'b@example.org'], $followed);
		$this->assertSame(['a@example.org', 'b@example.org'], $this->fetched);
	}

	/**
	 * The point of the button is that nobody follows six accounts by hand.
	 * Refusing all six because one host is down would defeat it.
	 */
	public function testOneUnreachableAccountDoesNotStopTheRest(): void {
		$this->configured = json_encode([[
			'slug' => 'three', 'name' => 'three',
			'handles' => ['a@example.org', 'gone@example.org', 'c@example.org'],
		]]);
		$this->resolvable = ['a@example.org', 'c@example.org'];

		$followed = $this->service->followAll($this->viewer(), 'three');

		$this->assertSame(['a@example.org', 'c@example.org'], $followed);
	}

	public function testTheViewerIsNotMadeToFollowThemselves(): void {
		$this->configured = json_encode([[
			'slug' => 'me', 'name' => 'me', 'handles' => ['alice@cloud.example', 'b@example.org'],
		]]);
		$this->resolvable = ['alice@cloud.example', 'b@example.org'];

		// the viewer's own id is what the resolver builds for that handle
		$viewer = new Person();
		$viewer->setId('https://cloud.example/users/alice');

		$followed = $this->service->followAll($viewer, 'me');

		$this->assertSame(['b@example.org'], $followed);
	}

	/** The rows a request reads, the index without the member lists. */
	private function request(array $rows): StarterPacksRequest|Stub {
		$request = $this->createStub(StarterPacksRequest::class);
		$request->method('getIndex')->willReturn(array_map(static function (array $row): array {
			$row['member_count'] = count($row['members']);
			unset($row['members'], $row['uri'], $row['fetched']);

			return $row;
		}, $rows));
		$request->method('getBySlug')->willReturnCallback(static function (string $slug) use ($rows): ?array {
			foreach ($rows as $row) {
				if ($row['slug'] === $slug) {
					return $row;
				}
			}

			return null;
		});

		return $request;
	}

	/** A service with packs read in from another server's collections. */
	private function withCollections(array $rows): StarterPackService {
		return $this->service($this->request($rows));
	}

	/** @return array<string, mixed> */
	private static function row(string $slug, array $members, string $origin = 'fediverse'): array {
		return [
			'id' => 1, 'uri' => 'https://mastodon.social/ap/users/1/collections/11', 'slug' => $slug, 'origin' => $origin,
			'curator' => 'https://mastodon.social/users/Gargron', 'curator_acct' => 'Gargron@mastodon.social',
			'name' => 'News organizations of Mastodon', 'summary' => 'Papers', 'topic' => 'news', 'language' => 'en',
			'url' => 'https://mastodon.social/collections/11', 'members' => $members, 'fetched' => 1,
		];
	}

	public function testACollectionReadInIsAPackAfterThoseThisInstanceChose(): void {
		$packs = $this->withCollections([self::row('news-1a2b3c4d', ['https://newsie.social/users/paper'])])->packs();

		$last = end($packs);
		$this->assertSame('news-1a2b3c4d', $last->getSlug());
		$this->assertSame(StarterPack::SOURCE_FEDIVERSE, $last->getSource());
		$this->assertSame('Gargron@mastodon.social', $last->getCurator());
		$this->assertSame('news', $last->getTopic());
		$this->assertSame(1, $last->jsonSerialize()['size']);
		$this->assertNotSame(StarterPack::SOURCE_FEDIVERSE, $packs[0]->getSource());
	}

	public function testTheIndexReadsNoMemberListAndCountsAsManyAsAPackOffers(): void {
		$members = array_map(static fn (int $n): string => 'https://m' . $n . '.example/users/u', range(1, 70));
		$request = $this->request([self::row('big-1a2b3c4d', $members)]);
		$request->method('getBySlug')->willThrowException(new RuntimeException('the index read a member list'));

		$packs = $this->service($request)->packs();

		$this->assertSame(StarterPackService::MAX_HANDLES, end($packs)->jsonSerialize()['size']);
	}

	public function testAPackWhoseCuratorThisInstanceKeepsOutIsNeitherListedNorOpened(): void {
		$this->moderated = ['https://mastodon.social/users/Gargron'];
		$service = $this->withCollections([self::row('news-1a2b3c4d', ['https://newsie.social/users/paper'])]);

		$slugs = array_map(static fn (StarterPack $pack): string => $pack->getSlug(), $service->packs());
		$this->assertNotContains('news-1a2b3c4d', $slugs);

		$this->expectException(ItemNotFoundException::class);
		$service->pack('news-1a2b3c4d');
	}

	public function testAMemberThisInstanceKeepsOutIsNeitherOfferedNorFollowed(): void {
		$this->configured = json_encode([[
			'slug' => 'two', 'name' => 'two', 'handles' => ['a@example.org', 'b@example.org'],
		]]);
		$this->resolvable = ['a@example.org', 'b@example.org'];
		$this->moderated = ['https://example.org/users/b'];

		$this->assertSame(['a@example.org'], array_map(static fn (Person $p): string => $p->getAccount(), $this->service->pack('two')->getAccounts()));
		$this->assertSame(['a@example.org'], $this->service->followAll($this->viewer(), 'two'));
		$this->assertSame(['a@example.org'], $this->followed);
	}

	public function testACollectionNeverReplacesAPackThisInstanceChose(): void {
		$packs = $this->withCollections([self::row('photography', ['https://newsie.social/users/paper'])])->packs();

		$photography = array_values(array_filter($packs, static fn (StarterPack $pack): bool => $pack->getSlug() === 'photography'));
		$this->assertCount(1, $photography);
		$this->assertSame(StarterPack::SOURCE_BUILTIN, $photography[0]->getSource());
	}

	public function testSuggestingNobodyHidesTheCollectionsToo(): void {
		$this->configured = '[]';

		$this->assertSame([], $this->withCollections([self::row('news-1a2b3c4d', ['https://newsie.social/users/paper'])])->packs());
	}

	public function testAnOpenedCollectionResolvesItsMembersByActorIdInOneLookup(): void {
		$paper = new Person();
		$paper->setId('https://newsie.social/users/paper');
		$paper->setAccount('paper@newsie.social');
		$members = ['https://newsie.social/users/paper', 'https://gone.example/users/blog'];
		$this->cacheActorService->expects($this->once())->method('getCachedFromIds')
			->with($members)->willReturn([$paper->getId() => $paper]);
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$pack = $this->withCollections([self::row('news-1a2b3c4d', $members)])->pack('news-1a2b3c4d');

		$this->assertSame([$paper], $pack->getAccounts());
		$this->assertSame(['https://gone.example/users/blog'], $pack->getUnresolved());
		$this->assertSame(['https://gone.example/users/blog'], $this->queued);
	}

	public function testASuggestionReadFromBlueskyIsABlueskyPack(): void {
		$packs = $this->withCollections([self::row('street-1a2b3c4d', ['https://bsky.app/profile/did:plc:a'], 'bluesky')])->packs();

		$this->assertSame(StarterPack::SOURCE_BLUESKY, end($packs)->getSource());
	}

	public function testThePeopleInAPackReadInAreFollowedAsTheActorsTheyAre(): void {
		$member = new Person();
		$member->setId('https://bsky.app/profile/did:plc:a');
		$member->setAccount('a.bsky.social');
		$this->cacheActorService->method('getCachedFromIds')->willReturn([$member->getId() => $member]);
		$follows = $this->createMock(FollowService::class);
		$follows->expects($this->once())->method('followActor')->with($this->anything(), $member)->willReturn(true);
		$follows->expects($this->never())->method('followAccount');
		$service = $this->service($this->request([self::row('street-1a2b3c4d', ['https://bsky.app/profile/did:plc:a'], 'bluesky')]), $follows);

		$this->assertSame(['a.bsky.social'], $service->followAll($this->viewer(), 'street-1a2b3c4d'));
	}
}
