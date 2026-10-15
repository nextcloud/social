<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Moderation\Blocklist;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Atproto\Reader\BlueskyActorService;
use OCA\Social\Atproto\Reader\SuggestedStarterPacks;
use OCA\Social\Db\StarterPacksRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Bluesky's suggested starter packs read in, against an AppView held in PHP.
 */
#[AllowMockObjectsWithoutExpectations]
class SuggestedStarterPacksTest extends TestCase {
	private const PACK = 'at://did:plc:creator/app.bsky.graph.starterpack/3lc4ignapzy22';
	private const LIST = 'at://did:plc:creator/app.bsky.graph.list/3lc4igl';

	/** @var array<string, array<string, mixed>> what is stored, by uri */
	private array $stored = [];
	/** @var list<int> */
	private array $deleted = [];
	/** @var list<array<string, mixed>> the suggested packs the AppView answers with */
	private array $suggested = [];
	/** @var array<string, list<array<string, mixed>>> each list's items */
	private array $lists = [];
	/** @var list<string> dids this instance blocks */
	private array $blocked = [];
	/** @var list<string> dids Bluesky's moderation limits */
	private array $limited = [];
	/** @var list<string> dids stored in the actor cache */
	private array $cachedNow = [];
	private bool $down = false;

	protected function setUp(): void {
		$this->suggested = [$this->view(self::PACK, 'did:plc:creator', 'Street Photographers', self::LIST)];
		$this->lists[self::LIST] = [$this->item('did:plc:one', 'one.bsky.social'), $this->item('did:plc:two', 'two.example.com')];
	}

	/** @return array<string, mixed> a starterPackView */
	private function view(string $uri, string $creator, string $name, string $list): array {
		return [
			'uri' => $uri,
			'creator' => ['did' => $creator, 'handle' => 'creator.bsky.social'],
			'record' => ['name' => $name, 'description' => 'People who shoot the street'],
			'list' => ['uri' => $list],
		];
	}

	/** @return array<string, mixed> a listItemView */
	private function item(string $did, string $handle): array {
		return ['subject' => ['did' => $did, 'handle' => $handle, 'displayName' => $handle]];
	}

	private function reader(): SuggestedStarterPacks {
		$appView = $this->createStub(AppViewClient::class);
		$appView->method('query')->willReturnCallback(function (string $method, array $params): array {
			if ($this->down) {
				throw new RuntimeException('AppView down');
			}

			return match ($method) {
				'app.bsky.unspecced.getSuggestedStarterPacks' => ['starterPacks' => $this->suggested],
				'app.bsky.graph.getList' => ['items' => $this->lists[$params['list']] ?? []],
				default => [],
			};
		});

		$mapper = $this->createStub(ActorMapper::class);
		$mapper->method('person')->willReturnCallback(function (array $profile): Person {
			$person = new Person();
			$person->setId('https://bsky.app/profile/' . $profile['did']);
			$person->setDetailArray(ActorMapper::DETAIL, ['limited' => in_array($profile['did'], $this->limited, true)]);

			return $person;
		});
		$actors = $this->createStub(BlueskyActorService::class);
		$actors->method('cached')->willReturn(null);
		$actors->method('store')->willReturnCallback(function (Person $person): void {
			$this->cachedNow[] = $person->getId();
		});
		$blocklist = $this->createStub(Blocklist::class);
		$blocklist->method('isBlockedDid')->willReturnCallback(fn (string $did): bool => in_array($did, $this->blocked, true));

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

		return new SuggestedStarterPacks($appView, $mapper, $actors, $blocklist, $request, $time, new NullLogger());
	}

	public function testASuggestedPackIsStoredWithItsMembersCached(): void {
		$this->assertSame(['packs' => 1, 'members' => 2], $this->reader()->refresh());

		$pack = $this->stored[self::PACK];
		$this->assertSame('bluesky', $pack['origin']);
		$this->assertSame(['https://bsky.app/profile/did:plc:one', 'https://bsky.app/profile/did:plc:two'], $pack['members']);
		$this->assertSame('Street Photographers', $pack['name']);
		$this->assertSame('People who shoot the street', $pack['summary']);
		$this->assertSame('creator.bsky.social', $pack['curator_acct']);
		$this->assertSame('https://bsky.app/profile/did:plc:creator', $pack['curator']);
		$this->assertSame('https://bsky.app/starter-pack/creator.bsky.social/3lc4ignapzy22', $pack['url']);
		$this->assertContains('https://bsky.app/profile/did:plc:one', $this->cachedNow);
	}

	public function testABlockedOrLimitedMemberIsLeftOut(): void {
		$this->blocked = ['did:plc:one'];
		$this->limited = ['did:plc:two'];
		$this->lists[self::LIST][] = $this->item('did:plc:three', 'three.bsky.social');

		$this->reader()->refresh();

		$this->assertSame(['https://bsky.app/profile/did:plc:three'], $this->stored[self::PACK]['members']);
	}

	public function testAPackByABlockedCreatorIsNotOffered(): void {
		$this->blocked = ['did:plc:creator'];

		$this->assertSame(0, $this->reader()->refresh()['packs']);
		$this->assertSame([], $this->stored);
	}

	public function testAPackWhoseIdIsNotItsCreatorsIsNotOffered(): void {
		$this->suggested = [$this->view(self::PACK, 'did:plc:someoneelse', 'Forged', self::LIST)];

		$this->reader()->refresh();

		$this->assertSame([], $this->stored);
	}

	public function testAPackBlueskyNoLongerSuggestsGoesAndTheFediversesStay(): void {
		$this->stored['https://mastodon.social/ap/users/1/collections/11'] = ['id' => 50, 'uri' => 'https://mastodon.social/ap/users/1/collections/11', 'origin' => 'fediverse'];
		$reader = $this->reader();
		$reader->refresh();
		$this->suggested = [];

		$reader->refresh();

		$this->assertArrayNotHasKey(self::PACK, $this->stored);
		$this->assertArrayHasKey('https://mastodon.social/ap/users/1/collections/11', $this->stored);
	}

	public function testAnAppViewThatDoesNotAnswerKeepsWhatWasRead(): void {
		$reader = $this->reader();
		$reader->refresh();
		$this->down = true;

		try {
			$reader->refresh();
			$this->fail('an AppView that does not answer is an error the caller counts');
		} catch (RuntimeException) {
		}

		$this->assertArrayHasKey(self::PACK, $this->stored);
	}
}
