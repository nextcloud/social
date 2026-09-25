<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\FiltersRequest;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCA\Social\Model\Client\FilterStatus;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Exercises FiltersRequest against the real database the CI job installs, for
 * the half the mocked suite cannot reach: that `social_filter_st` exists with
 * the columns the migration declares, and that a filter comes back carrying
 * both of its halves however it was read.
 *
 * That last one is the bug this file was written for. A filter read on its own
 * (`getById()`, which is what every route addressing one filter uses) hydrated
 * its keywords and not its statuses, so `GET /api/v2/filters/{id}/statuses`
 * answered `[]` for a filter that had some — while `GET
 * /api/v2/filters/statuses/{id}` found the same row. The controller test could
 * not catch it: its stubbed store is hand-written and was more generous than
 * the real one.
 */
class FiltersRequestTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/users/alice-filter-itest';
	private const BOB = 'https://remote.example/users/bob-filter-itest';

	private FiltersRequest $request;

	protected function setUp(): void {
		parent::setUp();
		$this->request = Server::get(FiltersRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach ([self::ALICE, self::BOB] as $actorId) {
			$this->request->deleteRelatedId($actorId);
		}
	}

	private function stored(string $actorId = self::ALICE): Filter {
		$filter = (new Filter())
			->setActorId($actorId)
			->setTitle('spoilers')
			->setContexts([Filter::CONTEXT_HOME])
			->setAction(Filter::ACTION_WARN)
			->addKeyword((new FilterKeyword())->setKeyword('banana'));
		$this->request->save($filter);

		return $filter;
	}

	public function testAFilterReadOnItsOwnCarriesItsStatuses(): void {
		$filter = $this->stored();
		$this->request->saveStatus(
			(new FilterStatus())->setFilterId($filter->getId())->setStatusId(4242)
		);

		$read = $this->request->getById($filter->getId(), self::ALICE);

		$this->assertCount(1, $read->getStatuses());
		$this->assertSame('4242', $read->getStatuses()[0]->getStatusId());
		$this->assertCount(1, $read->getKeywords());
	}

	public function testAFilterInAListCarriesThemToo(): void {
		$filter = $this->stored();
		$this->request->saveStatus(
			(new FilterStatus())->setFilterId($filter->getId())->setStatusId(7)
		);

		$filters = $this->request->getByActor(self::ALICE);

		$this->assertCount(1, $filters);
		$this->assertTrue($filters[0]->covers(7));
		$this->assertFalse($filters[0]->covers(8));
	}

	public function testAnEntryIsReadBackByItsOwnId(): void {
		$filter = $this->stored();
		$entry = (new FilterStatus())->setFilterId($filter->getId())->setStatusId(11);
		$id = $this->request->saveStatus($entry);

		$read = $this->request->getStatusById($id, self::ALICE);

		$this->assertSame('11', $read->getStatusId());
		$this->assertSame($filter->getId(), $read->getFilterId());
	}

	/** The ownership check is the join, not a comparison made afterwards. */
	public function testAnotherAccountCannotReadOrDeleteAnEntry(): void {
		$filter = $this->stored(self::BOB);
		$id = $this->request->saveStatus(
			(new FilterStatus())->setFilterId($filter->getId())->setStatusId(12)
		);

		$this->expectException(ItemNotFoundException::class);
		$this->request->getStatusById($id, self::ALICE);
	}

	public function testDeletingAFilterTakesItsEntriesWithIt(): void {
		$filter = $this->stored();
		$id = $this->request->saveStatus(
			(new FilterStatus())->setFilterId($filter->getId())->setStatusId(13)
		);

		$this->request->delete($filter->getId(), self::ALICE);

		$this->assertSame([], $this->request->statusesOf([$filter->getId()]));
		$this->expectException(ItemNotFoundException::class);
		$this->request->getStatusById($id, self::ALICE);
	}

	/** Two filters of one account may each cover the same post. */
	public function testTwoFiltersMayCoverTheSamePost(): void {
		$first = $this->stored();
		$second = $this->stored();

		$this->request->saveStatus((new FilterStatus())->setFilterId($first->getId())->setStatusId(99));
		$this->request->saveStatus((new FilterStatus())->setFilterId($second->getId())->setStatusId(99));

		$statuses = $this->request->statusesOf([$first->getId(), $second->getId()]);

		$this->assertCount(1, $statuses[$first->getId()]);
		$this->assertCount(1, $statuses[$second->getId()]);
	}

	/**
	 * A change that fails halfway leaves nothing of itself behind.
	 *
	 * `PUT /api/v2/filters/{id}` writes the filter row and then the keywords
	 * one at a time, and a keyword naming another filter is refused — so the
	 * title had already been changed by the time the request answered 404, and
	 * a client retrying what it was told had failed retried against state that
	 * had partly moved. This is the sequence that route runs, with the refusal
	 * where the route hits it.
	 */
	public function testAFailedChangeLeavesTheFilterAsItWas(): void {
		$filter = $this->stored();
		$keyword = $filter->getKeywords()[0];

		try {
			$this->request->transactional(function () use ($filter, $keyword): void {
				$this->request->update($filter->setTitle('renamed'));
				$this->request->updateKeyword($keyword->setKeyword('cherry'));

				throw new ItemNotFoundException('filter keyword not found');
			});
			$this->fail('the refusal did not reach the caller');
		} catch (ItemNotFoundException) {
		}

		$read = $this->request->getById($filter->getId(), self::ALICE);

		$this->assertSame('spoilers', $read->getTitle(), 'the title was written by a request that failed');
		$this->assertSame('banana', $read->getKeywords()[0]->getKeyword());
	}

	/** And one that does not fail commits all of it, not some of it. */
	public function testAChangeThatSucceedsCommitsEveryPartOfIt(): void {
		$filter = $this->stored();
		$keyword = $filter->getKeywords()[0];

		$this->request->transactional(function () use ($filter, $keyword): void {
			$this->request->update($filter->setTitle('renamed'));
			$this->request->updateKeyword($keyword->setKeyword('cherry'));
		});

		$read = $this->request->getById($filter->getId(), self::ALICE);

		$this->assertSame('renamed', $read->getTitle());
		$this->assertSame('cherry', $read->getKeywords()[0]->getKeyword());
	}

	/**
	 * A filter written inside a caller's transaction is written once.
	 *
	 * `save()` opens its own, and the controller wraps the call: without the
	 * re-entrancy check that is a transaction inside a transaction, which the
	 * connection does not take.
	 */
	public function testSavingInsideACallersTransactionStillWorks(): void {
		$filter = (new Filter())
			->setActorId(self::ALICE)
			->setTitle('nested')
			->setContexts([Filter::CONTEXT_HOME])
			->setAction(Filter::ACTION_WARN)
			->addKeyword((new FilterKeyword())->setKeyword('durian'));

		$this->request->transactional(function () use ($filter): void {
			$this->request->save($filter);
		});

		$read = $this->request->getById($filter->getId(), self::ALICE);

		$this->assertSame('nested', $read->getTitle());
		$this->assertCount(1, $read->getKeywords());
	}
}
