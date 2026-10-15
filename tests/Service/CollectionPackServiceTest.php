<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StarterPacksRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CollectionPackService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Reading other servers' collections (FEP-7aa9) in as starter packs, against
 * a network held in PHP: a curator on mastodon.social with collections whose
 * members live on several servers, each with an authorization of its own.
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

	private function service(): CollectionPackService {
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
		$actors->method('getCachedFromIds')->willReturn([]);

		$curl = $this->createStub(CurlService::class);
		$curl->method('retrieveObject')->willReturnCallback(function (string $id): array {
			if (!isset($this->documents[$id]) || in_array($id, $this->down, true)) {
				throw new RuntimeException('not reached: ' . $id);
			}

			return $this->documents[$id];
		});
		$curl->method('retrieveObjectsMany')->willReturnCallback(function (array $ids): array {
			$answers = [];
			foreach ($ids as $id) {
				$answers[$id] = in_array($id, $this->down, true) ? null : ($this->documents[$id] ?? null);
			}

			return $answers;
		});

		$request = $this->createStub(StarterPacksRequest::class);
		$request->method('save')->willReturnCallback(function (array $pack): void {
			$this->stored[$pack['uri']] = $pack + ['id' => $this->stored[$pack['uri']]['id'] ?? count($this->stored) + 1];
		});
		$request->method('getAll')->willReturnCallback(fn (): array => array_values($this->stored));
		$request->method('deleteById')->willReturnCallback(function (int $id): void {
			$this->deleted[] = $id;
			$this->stored = array_filter($this->stored, static fn (array $pack): bool => $pack['id'] !== $id);
		});

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);

		return new CollectionPackService($config, $actors, $curl, $request, $time, new NullLogger());
	}

	public function testACuratorsCollectionIsStoredWithTheMembersWhoseConsentHolds(): void {
		$result = $this->service()->refresh();

		$this->assertSame(['sources' => 1, 'packs' => 1, 'members' => 2, 'refused' => 0, 'failed' => 0], $result);
		$pack = $this->stored[self::NEWS];
		$this->assertSame([self::PAPER, self::TIMES], $pack['members']);
		$this->assertSame('News organizations of Mastodon', $pack['name']);
		$this->assertSame('Papers & more', $pack['summary']);
		$this->assertSame('news', $pack['topic']);
		$this->assertSame('en', $pack['language']);
		$this->assertSame('Gargron@mastodon.social', $pack['curator_acct']);
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

		$this->assertMatchesRegularExpression('/^artists-of-mastodon-[0-9a-f]{8}$/', $one);
		$this->assertNotSame($one, $two);
		$this->assertMatchesRegularExpression('/^collection-[0-9a-f]{8}$/', CollectionPackService::slug('✨', 'https://a.example/c/3'));
	}
}
