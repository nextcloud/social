<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\HashtagService;
use OCA\Social\Service\SearchService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SearchServiceTest extends TestCase {
	private CacheActorService|MockObject $cacheActorService;
	private HashtagService|MockObject $hashtagService;
	private SearchService $service;

	protected function setUp(): void {
		$this->cacheActorService = $this->createMock(CacheActorService::class);
		$this->hashtagService = $this->createMock(HashtagService::class);
		$this->service = new SearchService(
			$this->cacheActorService,
			$this->hashtagService,
			$this->createMock(ConfigService::class),
			new NullLogger(),
		);
	}

	public function testSearchUriResolvesAnActorUrl(): void {
		$bob = new Person();
		$this->cacheActorService->expects($this->once())
			->method('getFromId')
			->with('https://remote.example/users/bob')
			->willReturn($bob);

		$this->assertSame([$bob], $this->service->searchUri('https://remote.example/users/bob'));
	}

	public function testSearchUriSwallowsResolutionFailures(): void {
		$this->cacheActorService->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());

		$this->assertSame([], $this->service->searchUri('https://remote.example/users/nobody'));
	}

	/** @return array<string, array{string}> */
	public function nonUriSearchProvider(): array {
		return [
			'empty' => [''],
			'account' => ['@bob@remote.example'],
			'hashtag' => ['#nextcloud'],
		];
	}

	/** @dataProvider nonUriSearchProvider */
	public function testSearchUriIgnoresNonUriSearches(string $search): void {
		$this->cacheActorService->expects($this->never())->method('getFromId');

		$this->assertSame([], $this->service->searchUri($search));
	}

	public function testSearchAccountsCachesTheExactMatchThenSearchesTheCache(): void {
		$bob = new Person();
		$this->cacheActorService->expects($this->once())
			->method('getFromAccount')
			->with('bob@remote.example');
		$this->cacheActorService->expects($this->once())
			->method('searchCachedAccounts')
			->with('bob@remote.example')
			->willReturn([$bob]);

		$this->assertSame([$bob], $this->service->searchAccounts('@bob@remote.example'));
	}

	public function testSearchAccountsStillSearchesTheCacheWhenTheExactLookupFails(): void {
		$this->cacheActorService->method('getFromAccount')->willThrowException(new CacheActorDoesNotExistException());
		$this->cacheActorService->expects($this->once())
			->method('searchCachedAccounts')
			->with('bob')
			->willReturn([]);

		$this->assertSame([], $this->service->searchAccounts('bob'));
	}

	public function testSearchAccountsWithEmptySearchDoesNothing(): void {
		$this->cacheActorService->expects($this->never())->method($this->anything());

		$this->assertSame([], $this->service->searchAccounts(''));
	}

	public function testSearchHashtagsStripsTheHashSign(): void {
		$this->hashtagService->expects($this->once())
			->method('searchHashtags')
			->with('nextcloud', true)
			->willReturn([['hashtag' => '#nextcloud']]);

		$this->assertSame([['hashtag' => '#nextcloud']], $this->service->searchHashtags('#nextcloud'));
	}

	public function testSearchHashtagsAcceptsFreeText(): void {
		$this->hashtagService->expects($this->once())
			->method('searchHashtags')
			->with('next', true)
			->willReturn([]);

		$this->assertSame([], $this->service->searchHashtags('next'));
	}

	public function testSearchHashtagsWithEmptySearchDoesNothing(): void {
		$this->hashtagService->expects($this->never())->method('searchHashtags');

		$this->assertSame([], $this->service->searchHashtags(''));
	}

	public function testSearchStreamContentIsNotImplemented(): void {
		$this->assertSame([], $this->service->searchStreamContent('anything'));
		$this->assertSame([], $this->service->searchStreamContent(''));
	}

	public function testSearchAccountsIgnoresAHashtagSearch(): void {
		$this->cacheActorService->expects($this->never())->method('getFromAccount');
		$this->cacheActorService->expects($this->never())->method('searchCachedAccounts');

		$this->assertSame([], $this->service->searchAccounts('#nextcloud'));
	}

	public function testSearchHashtagsIgnoresAnAccountSearch(): void {
		$this->hashtagService->expects($this->never())->method('searchHashtags');

		$this->assertSame([], $this->service->searchHashtags('@bob@remote.example'));
	}
}
