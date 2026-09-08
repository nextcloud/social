<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Search;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\Search\UnifiedSearchResult;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UnifiedSearchProviderTest extends TestCase {
	/** @var IL10N&MockObject */
	private $l10n;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var SearchService&MockObject */
	private $searchService;
	private UnifiedSearchProvider $provider;

	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->searchService = $this->createMock(SearchService::class);

		$this->provider = new UnifiedSearchProvider(
			$this->l10n,
			$this->urlGenerator,
			$this->createMock(StreamService::class),
			$this->createMock(StreamRequest::class),
			$this->createMock(FollowService::class),
			$this->createMock(CacheActorService::class),
			$this->createMock(AccountService::class),
			$this->searchService,
			$this->createMock(ConfigService::class),
			new NullLogger()
		);
	}

	public function testStatusesJoinTheResultsWithExcerptAndLink(): void {
		$alice = new Person();
		$alice->setId('https://cloud.example/@alice');
		$alice->setPreferredUsername('alice');
		$alice->setAccount('alice@cloud.example');

		$local = new Note();
		$local->setId('https://cloud.example/@alice/1');
		$local->setNid(7);
		$local->setContent('<p>The   quick brown fox jumps over the lazy dog, ' . str_repeat('again and ', 20) . 'again</p>');
		$local->setLocal(true);
		$local->setActor($alice);

		$remote = new Note();
		$remote->setId('https://remote.example/notes/9');
		$remote->setContent('<p>remote hit</p>');
		$remote->setLocal(false);

		$this->searchService->method('searchStreamContent')->with('fox')->willReturn([$local, $remote]);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('social.ActivityPub.displayPost', ['username' => 'alice', 'token' => '7'])
			->willReturn('https://cloud.example/apps/social/@alice/7');

		$entries = $this->provider->search($this->user(), $this->query('fox'))->jsonSerialize()['entries'];

		$this->assertCount(2, $entries);
		$first = $entries[0]->jsonSerialize();
		$this->assertStringStartsWith('The quick brown fox', $first['title'], 'whitespace collapsed, tags stripped');
		$this->assertLessThanOrEqual(120, mb_strlen($first['title']));
		$this->assertStringEndsWith('…', $first['title']);
		$this->assertSame('@alice@cloud.example', $first['subline']);
		$this->assertSame('https://cloud.example/apps/social/@alice/7', $first['resourceUrl']);

		$second = $entries[1]->jsonSerialize();
		$this->assertSame('https://remote.example/notes/9', $second['resourceUrl'], 'remote statuses link to their origin');
	}

	/** @return IUser&MockObject */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		return $user;
	}

	/** @return ISearchQuery&MockObject */
	private function query(string $term, int $limit = 5, ?int $cursor = null): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);

		return $query;
	}

	/** @return Person&MockObject */
	private function account(string $username, string $account, ?string $iconUrl = null): Person {
		$person = $this->createMock(Person::class);
		$person->method('getPreferredUsername')->willReturn($username);
		$person->method('getAccount')->willReturn($account);
		$person->method('hasIcon')->willReturn($iconUrl !== null);
		if ($iconUrl !== null) {
			$icon = $this->createMock(Document::class);
			$icon->method('getUrl')->willReturn($iconUrl);
			$person->method('getIcon')->willReturn($icon);
		}

		return $person;
	}

	public function testIdentity(): void {
		$this->assertSame('social', $this->provider->getId());
		$this->assertSame('Social', $this->provider->getName());
		$this->assertSame(12, $this->provider->getOrder('files.View.index', []));
		$this->assertSame(12, $this->provider->getOrder('social.Navigation.navigate', ['path' => 'home']));
	}

	public function testSearchTrimsTheTermAndQueriesAccountsUrisAndHashtags(): void {
		$this->searchService->expects($this->once())->method('searchUri')->with('alice')->willReturn([]);
		$this->searchService->expects($this->once())->method('searchAccounts')->with('alice')->willReturn([]);
		$this->searchService->expects($this->once())->method('searchHashtags')->with('alice')->willReturn([]);

		$result = $this->provider->search($this->user(), $this->query('  alice '));

		$this->assertSame('Social', $result->jsonSerialize()['name']);
		$this->assertSame([], $result->jsonSerialize()['entries']);
	}

	public function testAccountsBecomeEntriesLinkingToTheActorPage(): void {
		$this->searchService->method('searchUri')->willReturn([$this->account('bob', 'bob@remote.example', 'https://remote.example/bob.png')]);
		$this->searchService->method('searchAccounts')->willReturn([$this->account('alice', 'alice@cloud.example')]);
		$this->searchService->method('searchHashtags')->willReturn([]);
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(
			fn (string $route, array $args): string => $route . '?username=' . $args['username']
		);

		$entries = $this->provider->search($this->user(), $this->query('x'))->jsonSerialize()['entries'];

		$this->assertCount(2, $entries);
		$this->assertContainsOnlyInstancesOf(UnifiedSearchResult::class, $entries);
		[$bob, $alice] = $entries;
		$this->assertSame('bob', $bob->getTitle());
		$this->assertSame('@bob@remote.example', $bob->getSubline());
		$this->assertSame('social.ActivityPub.actorAlias?username=bob@remote.example', $bob->getResourceUrl());
		$this->assertSame('https://remote.example/bob.png', $bob->getThumbnailUrl());
		$this->assertSame('https://remote.example/bob.png', $bob->getIcon());
		$this->assertSame('alice', $alice->getTitle());
		$this->assertSame('', $alice->getThumbnailUrl(), 'accounts without icon have no thumbnail');
	}

	public function testHashtagsBecomeEntriesLinkingToTheTagTimeline(): void {
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchAccounts')->willReturn([]);
		$this->searchService->method('searchHashtags')->willReturn([
			['hashtag' => 'nextcloud', 'trend' => ['10d' => 42]],
		]);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('social.Navigation.timeline', ['path' => 'tags/nextcloud'])
			->willReturn('https://cloud.example/apps/social/timeline/tags/nextcloud');

		$entries = $this->provider->search($this->user(), $this->query('nextcloud'))->jsonSerialize()['entries'];

		$this->assertCount(1, $entries);
		$this->assertSame("42 posts related to 'nextcloud'", $entries[0]->getTitle());
		$this->assertSame('#nextcloud', $entries[0]->getSubline());
		$this->assertSame('https://cloud.example/apps/social/timeline/tags/nextcloud', $entries[0]->getResourceUrl());
		$this->assertSame('', $entries[0]->getThumbnailUrl());
	}

	public function testResultIsPaginatedFromTheCursor(): void {
		$this->searchService->method('searchUri')->willReturn([]);
		$this->searchService->method('searchAccounts')->willReturn([]);
		$this->searchService->method('searchHashtags')->willReturn([]);

		$first = $this->provider->search($this->user(), $this->query('x', 5))->jsonSerialize();
		$next = $this->provider->search($this->user(), $this->query('x', 5, 10))->jsonSerialize();

		$this->assertTrue($first['isPaginated']);
		$this->assertSame(5, $first['cursor']);
		$this->assertSame(15, $next['cursor']);
	}
}
