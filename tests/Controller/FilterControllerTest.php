<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\FilterController;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The routes a client edits its keyword filters with.
 *
 * What a filter *does* is `FilterServiceTest`'s; this is the contract a client
 * is handed — the entity shape, what a filter that is not the caller's answers,
 * what an incomplete filter answers, and which grant each route needs.
 *
 * The store below is an in-memory `FiltersRequest` that hands out copies, so a
 * route that changes a filter without writing it back fails here rather than
 * appearing to work.
 */
class FilterControllerTest extends TestCase {
	private const ALICE = 'https://cloud.example/users/alice';
	private const BOB = 'https://cloud.example/users/bob';

	/** @var IRequest&MockObject */
	private $request;
	private AccountService|MockObject $accountService;
	private ClientService|MockObject $clientService;
	private FiltersRequest|MockObject $filtersRequest;
	private IUserSession|MockObject $userSession;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var array<string, mixed> the parameters the request carries */
	private array $params = [];
	private bool $csrf = true;
	/** the actor the session resolves to */
	private string $viewerId = self::ALICE;

	/** @var array<int, Filter> the stored filters, by id */
	private array $filters = [];
	private int $nextFilterId = 1;
	private int $nextKeywordId = 1;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/social/api/v2/filters');
		$this->request->method('getParam')
			->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);

		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->accountService = $this->createMock(AccountService::class);
		$this->accountService->method('getActorFromUserId')
			->willReturnCallback(function (): Person {
				$viewer = new Person();
				$viewer->setId($this->viewerId);

				return $viewer;
			});

		$this->clientService = $this->createMock(ClientService::class);
		$this->stubStore();

		// Response::getHeaders() asks the container for the request
		\OC::$server->register(IRequest::class, $this->request);
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	private function stubStore(): void {
		$this->filtersRequest = $this->createMock(FiltersRequest::class);

		$this->filtersRequest->method('save')
			->willReturnCallback(function (Filter $filter): int {
				$filter->setId($this->nextFilterId++);
				foreach ($filter->getKeywords() as $keyword) {
					$keyword->setId($this->nextKeywordId++)->setFilterId($filter->getId());
				}
				$this->filters[$filter->getId()] = $this->copy($filter);

				return $filter->getId();
			});

		$this->filtersRequest->method('getByActor')
			->willReturnCallback(function (string $actorId): array {
				$filters = [];
				foreach (array_reverse($this->filters, true) as $filter) {
					if ($filter->getActorId() === $actorId) {
						$filters[] = $this->copy($filter);
					}
				}

				return $filters;
			});

		$this->filtersRequest->method('getById')
			->willReturnCallback(function (int $id, string $actorId): Filter {
				$filter = $this->filters[$id] ?? null;
				if ($filter === null || $filter->getActorId() !== $actorId) {
					throw new ItemNotFoundException('filter not found');
				}

				return $this->copy($filter);
			});

		$this->filtersRequest->method('update')
			->willReturnCallback(function (Filter $filter): void {
				$stored = $this->filters[$filter->getId()] ?? null;
				if ($stored === null || $stored->getActorId() !== $filter->getActorId()) {
					return;
				}

				$stored->setTitle($filter->getTitle())
					->setContexts($filter->getContexts())
					->setAction($filter->getAction())
					->setExpiresAt($filter->getExpiresAt());
			});

		$this->filtersRequest->method('delete')
			->willReturnCallback(function (int $id, string $actorId): void {
				if (($this->filters[$id] ?? null)?->getActorId() === $actorId) {
					unset($this->filters[$id]);
				}
			});

		$this->filtersRequest->method('saveKeyword')
			->willReturnCallback(function (FilterKeyword $keyword): int {
				$keyword->setId($this->nextKeywordId++);
				$this->filters[$keyword->getFilterId()]->addKeyword($this->copyKeyword($keyword));

				return $keyword->getId();
			});

		$this->filtersRequest->method('getKeywordById')
			->willReturnCallback(function (int $id, string $actorId): FilterKeyword {
				foreach ($this->filters as $filter) {
					if ($filter->getActorId() !== $actorId) {
						continue;
					}
					foreach ($filter->getKeywords() as $keyword) {
						if ($keyword->getId() === $id) {
							return $this->copyKeyword($keyword);
						}
					}
				}

				throw new ItemNotFoundException('filter keyword not found');
			});

		$this->filtersRequest->method('updateKeyword')
			->willReturnCallback(function (FilterKeyword $keyword): void {
				foreach ($this->filters as $filter) {
					foreach ($filter->getKeywords() as $stored) {
						if ($stored->getId() === $keyword->getId()) {
							$stored->setKeyword($keyword->getKeyword())
								->setWholeWord($keyword->isWholeWord());
						}
					}
				}
			});

		$this->filtersRequest->method('deleteKeyword')
			->willReturnCallback(function (int $id, string $actorId): void {
				foreach ($this->filters as $filter) {
					if ($filter->getActorId() !== $actorId) {
						continue;
					}

					$filter->setKeywords(
						array_filter(
							$filter->getKeywords(),
							static fn (FilterKeyword $keyword): bool => $keyword->getId() !== $id
						)
					);
				}
			});
	}

	private function copy(Filter $filter): Filter {
		$copy = (new Filter())
			->setId($filter->getId())
			->setActorId($filter->getActorId())
			->setTitle($filter->getTitle())
			->setContexts($filter->getContexts())
			->setAction($filter->getAction())
			->setExpiresAt($filter->getExpiresAt());

		foreach ($filter->getKeywords() as $keyword) {
			$copy->addKeyword($this->copyKeyword($keyword));
		}

		return $copy;
	}

	private function copyKeyword(FilterKeyword $keyword): FilterKeyword {
		return (new FilterKeyword())
			->setId($keyword->getId())
			->setFilterId($keyword->getFilterId())
			->setKeyword($keyword->getKeyword())
			->setWholeWord($keyword->isWholeWord());
	}

	/**
	 * The bearer token is parsed in the constructor, so a test that presents
	 * one has to say so before the controller exists.
	 */
	private function controller(string $authorization = ''): FilterController {
		// the getHeader() callback is registered once, in setUp(): a second
		// method() on the same mock never wins over the first
		$this->headers = ['Authorization' => $authorization];

		return new FilterController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->clientService,
			$this->filtersRequest,
		);
	}

	/** A stored filter of the given account, however the API would have made one. */
	private function stored(string $actorId, string $title = 'spoilers'): Filter {
		$filter = (new Filter())
			->setActorId($actorId)
			->setTitle($title)
			->setContexts([Filter::CONTEXT_HOME])
			->setAction(Filter::ACTION_WARN)
			->addKeyword((new FilterKeyword())->setKeyword('banana'));

		$this->filtersRequest->save($filter);

		return $filter;
	}

	// Mastodon's v1 filters, over the v2 ones

	/**
	 * A v1 filter is a v2 *keyword* carrying its parent's contexts and expiry,
	 * because v1 has no notion of a filter with several phrases. The two APIs
	 * therefore number different things, which is what this pins.
	 */
	public function testAV1FilterIsAKeywordOfAV2Filter(): void {
		$this->controller()->create(
			'spoilers', ['home', 'public'], 'hide', null,
			[['keyword' => 'banana'], ['keyword' => 'mango']]
		);

		$data = $this->controller()->indexV1()->getData();

		$this->assertCount(2, $data, 'one v1 filter per keyword, not per filter');
		$this->assertSame('banana', $data[0]['phrase']);
		$this->assertSame(['home', 'public'], $data[0]['context']);
		$this->assertTrue($data[0]['irreversible'], 'v1 name for filter_action: hide');
		$this->assertSame('mango', $data[1]['phrase']);
	}

	public function testCreatingAV1FilterMakesAFilterHoldingThatOneKeyword(): void {
		$data = $this->controller()->createV1('banana', ['home'], 'true', 'true')->getData();

		$this->assertSame('banana', $data['phrase']);
		$this->assertTrue($data['whole_word']);
		$this->assertTrue($data['irreversible']);
		$this->assertSame('banana', $this->filters[1]->getTitle());
		$this->assertCount(1, $this->filters[1]->getKeywords());
	}

	public function testChangingAV1FilterLeavesWhatItDidNotName(): void {
		$this->controller()->createV1('banana', ['home', 'thread'], 'true');

		$this->controller()->updateV1(1, 'mango');
		$data = $this->controller()->getV1(1)->getData();

		$this->assertSame('mango', $data['phrase']);
		$this->assertSame(['home', 'thread'], $data['context'], 'the contexts were not named');
		$this->assertTrue($data['irreversible'], 'nor was the action');
	}

	/**
	 * A v2 filter with no keywords matches nothing; left behind it would show
	 * in the v2 list as an empty filter the user never made.
	 */
	public function testDeletingTheLastV1FilterTakesTheFilterWithIt(): void {
		$this->controller()->createV1('banana', ['home']);

		$this->controller()->deleteV1(1);

		$this->assertSame([], $this->controller()->index()->getData());
	}

	/** A 403 would tell the caller it is there. */
	public function testAV1FilterOfAnotherAccountIsNotFound(): void {
		$bob = $this->stored(self::BOB);
		$keywords = $bob->getKeywords();

		$response = $this->controller()->getV1($keywords[0]->getId());

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testCreatingAFilterAnswersWithTheFilter(): void {
		$response = $this->controller()->create(
			'spoilers',
			['home', 'public'],
			'hide',
			null,
			[['keyword' => 'banana', 'whole_word' => 'true']]
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('1', $data['id'], 'ids are strings in the client API');
		$this->assertSame('spoilers', $data['title']);
		$this->assertSame(['home', 'public'], $data['context']);
		$this->assertSame('hide', $data['filter_action']);
		$this->assertNull($data['expires_at']);
		$this->assertSame(
			[['id' => '1', 'keyword' => 'banana', 'whole_word' => true]], $data['keywords']
		);
	}

	public function testAFilterIsCreatedForTheViewerAndNobodyElse(): void {
		$this->controller()->create('spoilers', ['home']);

		$this->assertSame(self::ALICE, $this->filters[1]->getActorId());
	}

	public function testTheActionDefaultsToWarn(): void {
		// the action that leaves the status where it is, which is the one a
		// client can undo by looking at it
		$data = $this->controller()->create('spoilers', ['home'])->getData();

		$this->assertSame('warn', $data['filter_action']);
	}

	public function testAnExpiryIsCountedFromNow(): void {
		$before = time();

		$data = $this->controller()->create('spoilers', ['home'], 'warn', '3600')->getData();

		$this->assertGreaterThanOrEqual($before + 3600, $this->filters[1]->getExpiresAt());
		$this->assertNotNull($data['expires_at']);
	}

	public function testAFilterWithNoTitleOrNoKnownContextIsRefused(): void {
		$noTitle = $this->controller()->create('', ['home']);
		$noContext = $this->controller()->create('spoilers', []);
		$unknownContext = $this->controller()->create('spoilers', ['elsewhere']);

		foreach ([$noTitle, $noContext, $unknownContext] as $response) {
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
			$this->assertArrayHasKey('error', $response->getData());
		}

		$this->assertSame([], $this->filters, 'nothing is stored');
	}

	public function testAnActionNothingAppliesIsRefused(): void {
		$response = $this->controller()->create('spoilers', ['home'], 'blur');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame([], $this->filters);
	}

	public function testAnEmptyKeywordIsRefused(): void {
		// it would match every status there is
		$response = $this->controller()->create(
			'spoilers', ['home'], 'warn', null, [['keyword' => '   ']]
		);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame([], $this->filters);
	}

	public function testTheListIsTheViewersFiltersAndNobodyElses(): void {
		$this->stored(self::ALICE, 'mine');
		$this->stored(self::BOB, 'theirs');

		$data = $this->controller()->index()->getData();

		$this->assertSame(['mine'], array_column($data, 'title'));
	}

	public function testAnotherAccountsFilterDoesNotExist(): void {
		// a 403 would tell the caller it is there
		$bob = $this->stored(self::BOB);

		$response = $this->controller()->get($bob->getId());

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAnotherAccountsFilterCannotBeChangedOrDeleted(): void {
		$bob = $this->stored(self::BOB);

		$updated = $this->controller()->update($bob->getId(), 'stolen');
		$deleted = $this->controller()->delete($bob->getId());

		$this->assertSame(Http::STATUS_NOT_FOUND, $updated->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $deleted->getStatus());
		$this->assertSame('spoilers', $this->filters[$bob->getId()]->getTitle());
		$this->assertArrayHasKey($bob->getId(), $this->filters);
	}

	public function testWhatAnUpdateDoesNotNameIsLeftAsItIs(): void {
		$filter = $this->stored(self::ALICE);

		$data = $this->controller()->update($filter->getId(), 'renamed')->getData();

		$this->assertSame('renamed', $data['title']);
		$this->assertSame(['home'], $data['context'], 'the contexts are not cleared');
		$this->assertSame(
			['banana'], array_column($data['keywords'], 'keyword'), 'the keywords are not cleared'
		);
	}

	public function testAnUpdateStoresWhatItAnswers(): void {
		$filter = $this->stored(self::ALICE);

		$this->controller()->update($filter->getId(), 'renamed', ['public'], 'hide');

		$stored = $this->filters[$filter->getId()];
		$this->assertSame('renamed', $stored->getTitle());
		$this->assertSame(['public'], $stored->getContexts());
		$this->assertSame('hide', $stored->getAction());
	}

	public function testAnUpdateThatNamesNoExpiryLeavesTheExpiryAlone(): void {
		$filter = $this->stored(self::ALICE);
		$this->filters[$filter->getId()]->setExpiresAt(1789234567);

		$this->controller()->update($filter->getId(), 'renamed');

		$this->assertSame(1789234567, $this->filters[$filter->getId()]->getExpiresAt());
	}

	public function testAnExplicitlyEmptyExpiryStopsTheFilterExpiring(): void {
		$filter = $this->stored(self::ALICE);
		$this->filters[$filter->getId()]->setExpiresAt(1789234567);
		$this->params['expires_in'] = '';

		$data = $this->controller()->update($filter->getId())->getData();

		$this->assertSame(0, $this->filters[$filter->getId()]->getExpiresAt());
		$this->assertNull($data['expires_at']);
	}

	public function testKeywordAttributesAddChangeAndRemoveKeywords(): void {
		$filter = $this->stored(self::ALICE);
		$existing = $filter->getKeywords()[0]->getId();

		$data = $this->controller()->update(
			$filter->getId(),
			null,
			null,
			null,
			[
				['id' => (string)$existing, 'keyword' => 'apple', 'whole_word' => 'true'],
				['keyword' => 'pear'],
			]
		)->getData();

		$this->assertSame(['apple', 'pear'], array_column($data['keywords'], 'keyword'));
		$this->assertSame([true, false], array_column($data['keywords'], 'whole_word'));

		$removed = $this->controller()->update(
			$filter->getId(), null, null, null, [['id' => (string)$existing, '_destroy' => 'true']]
		)->getData();

		$this->assertSame(['pear'], array_column($removed['keywords'], 'keyword'));
	}

	public function testAKeywordOfAnotherFilterCannotBeEditedThroughThisOne(): void {
		$mine = $this->stored(self::ALICE, 'mine');
		$other = $this->stored(self::ALICE, 'other');

		$response = $this->controller()->update(
			$mine->getId(), null, null, null,
			[['id' => (string)$other->getKeywords()[0]->getId(), 'keyword' => 'moved']]
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('banana', $this->filters[$other->getId()]->getKeywords()[0]->getKeyword());
	}

	public function testDeletingAFilterAnswersAnEmptyObject(): void {
		$filter = $this->stored(self::ALICE);

		$response = $this->controller()->delete($filter->getId());

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(new \stdClass(), $response->getData());
		$this->assertSame([], $this->filters);
	}

	public function testTheKeywordSubResourceListsAddsReadsChangesAndRemoves(): void {
		$filter = $this->stored(self::ALICE);

		$listed = $this->controller()->keywords($filter->getId())->getData();
		$this->assertSame(['banana'], array_column($listed, 'keyword'));

		$added = $this->controller()->addKeyword($filter->getId(), 'pear', 'true')->getData();
		$this->assertSame('pear', $added['keyword']);
		$this->assertTrue($added['whole_word']);

		$read = $this->controller()->getKeyword((int)$added['id'])->getData();
		$this->assertSame('pear', $read['keyword']);

		$changed = $this->controller()->updateKeyword((int)$added['id'], 'plum')->getData();
		$this->assertSame('plum', $changed['keyword']);
		$this->assertTrue($changed['whole_word'], 'what an update does not name is left as it is');

		$this->controller()->deleteKeyword((int)$added['id']);
		$this->assertSame(
			['banana'],
			array_column($this->controller()->keywords($filter->getId())->getData(), 'keyword')
		);
	}

	public function testAnotherAccountsKeywordDoesNotExist(): void {
		$bob = $this->stored(self::BOB);
		$keywordId = $bob->getKeywords()[0]->getId();

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->getKeyword($keywordId)->getStatus());
		$this->assertSame(
			Http::STATUS_NOT_FOUND, $this->controller()->updateKeyword($keywordId, 'stolen')->getStatus()
		);
		$this->assertSame(
			Http::STATUS_NOT_FOUND, $this->controller()->deleteKeyword($keywordId)->getStatus()
		);
		$this->assertSame('banana', $this->filters[$bob->getId()]->getKeywords()[0]->getKeyword());
	}

	public function testAKeywordCannotBeAddedToAnotherAccountsFilter(): void {
		$bob = $this->stored(self::BOB);

		$response = $this->controller()->addKeyword($bob->getId(), 'stolen');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['banana'], array_map(
			static fn (FilterKeyword $keyword): string => $keyword->getKeyword(),
			$this->filters[$bob->getId()]->getKeywords()
		));
	}

	public function testAnUnauthenticatedCallerIsToldSoRatherThanServedNothing(): void {
		$this->csrf = false;

		foreach ([$this->controller()->index(), $this->controller()->get(1),
			$this->controller()->create('spoilers', ['home']), $this->controller()->delete(1)] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertArrayHasKey('error', $response->getData());
		}

		$this->assertSame([], $this->filters);
	}

	public function testABearerTokenIsAcceptedWithoutASession(): void {
		// which is the only way a Mastodon client ever calls this
		$this->csrf = false;
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes(['read:filters', 'write:filters']);
		$this->clientService->method('getFromToken')->with('sometoken')->willReturn($client);

		$response = $this->controller('Bearer sometoken')->create('spoilers', ['home']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testARevokedTokenIsA401(): void {
		$this->csrf = false;
		$this->clientService->method('getFromToken')
			->willThrowException(new ClientNotFoundException('the access_token was revoked'));

		$response = $this->controller('Bearer stale')->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('the access_token was revoked', $response->getData()['error']);
	}

	public function testATokenThatMayReadFiltersMayNotWriteThem(): void {
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes(['read:filters']);
		$this->clientService->method('getFromToken')->willReturn($client);

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller('Bearer readonly')->create('spoilers', ['home'])->getStatus()
		);
		$this->assertSame([], $this->filters);
		$this->assertSame(
			Http::STATUS_OK, $this->controller('Bearer readonly')->index()->getStatus()
		);
	}
}
