<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Service\ConfigService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The predicate that keeps archived posts out of every list.
 *
 * Rows written before the column existed carry NULL, so the filter had to
 * admit a NULL beside a 0 — an `OR` on an unindexed column, which is one of
 * the inputs a planner estimates badly. Once `Version1000Date20261013100500`
 * has given every row a value and said so, the `OR` is dead weight and is
 * left out.
 */
#[AllowMockObjectsWithoutExpectations]
class ArchivedFilterTest extends TestCase {
	/** @var string[] */
	private array $where = [];

	private function builder(): SocialQueryBuilder&MockObject {
		$qb = $this->getMockBuilder(SocialQueryBuilder::class)
			->disableOriginalConstructor()
			->onlyMethods(['expr', 'createNamedParameter', 'andWhere'])
			->getMock();
		$qb->method('expr')->willReturn(new FakeExpressions());
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => var_export($value, true));
		$qb->method('andWhere')->willReturnCallback(function ($predicate) use ($qb) {
			$this->where[] = (string)$predicate;

			return $qb;
		});

		return $qb;
	}

	private function hideArchived(bool $filled, bool $withArchived = false, bool $admitUnfilled = false): void {
		$request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueBool')->willReturnCallback(
			static fn (string $key): bool => $key === ConfigService::SOCIAL_STREAM_ARCHIVED_FILLED && $filled
		);
		(new ReflectionProperty(StreamRequest::class, 'configService'))->setValue($request, $config);

		(new ReflectionMethod(StreamRequest::class, 'hideArchived'))
			->invoke($request, $this->builder(), $withArchived, $admitUnfilled);
	}

	public function testUntilEveryRowHasAValueANullIsAdmittedBesideAZero(): void {
		$this->hideArchived(false);

		$this->assertSame(['(s.archived = false OR s.archived IS NULL)'], $this->where);
	}

	public function testOnceEveryRowHasAValueTheFilterIsOneEquality(): void {
		$this->hideArchived(true);

		$this->assertSame(['s.archived = false'], $this->where);
	}

	/** the hashtag page, whose plan on MySQL starts from the tag only with the `OR` */
	public function testAQueryThatAsksForItKeepsTheNullBranch(): void {
		$this->hideArchived(true, false, true);

		$this->assertSame(['(s.archived = false OR s.archived IS NULL)'], $this->where);
	}

	public function testAReadThatAsksForArchivedPostsIsNotFiltered(): void {
		$this->hideArchived(true, true);

		$this->assertSame([], $this->where);
	}
}
