<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\SearchIndex;
use OCA\Social\Db\SearchTermsRequest;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The job that adds the posts stored before the word index to it: a bounded
 * run from a kept cursor, re-queued until it reaches the newest post, and
 * then never again — and never held for ever by one post it cannot index.
 */
#[AllowMockObjectsWithoutExpectations]
class SearchIndexTest extends TestCase {
	private SearchTermsRequest&MockObject $terms;
	private IJobList&MockObject $jobList;
	private LoggerInterface&MockObject $logger;
	/** @var array<string, string> the app settings */
	private array $settings = [];
	private int $now = 1_760_000_000;

	private function job(bool $ready = false): SearchIndex {
		$this->terms = $this->createMock(SearchTermsRequest::class);
		$this->terms->method('isReady')->willReturn($ready);
		$this->jobList = $this->createMock(IJobList::class);

		$config = $this->createMock(ConfigService::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->settings[$key] ?? '');
		$config->method('getAppValueInt')->willReturnCallback(fn (string $key): int => (int)($this->settings[$key] ?? '0'));
		$config->method('setAppValue')->willReturnCallback(function (string $key, $value): void {
			$this->settings[$key] = (string)$value;
		});
		$config->method('deleteAppValue')->willReturnCallback(function (string $key): void {
			unset($this->settings[$key]);
		});
		$this->logger = $this->createMock(LoggerInterface::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new SearchIndex($time, $this->terms, $config, $this->jobList, $this->logger);
	}

	private function runJob(SearchIndex $job): void {
		(new \ReflectionMethod(SearchIndex::class, 'run'))->invoke($job, null);
	}

	public function testItWalksFromTheKeptCursorAndStopsAtTheNewestPost(): void {
		$this->settings[SearchTermsRequest::CURSOR] = '100';
		$job = $this->job();
		$this->terms->expects($this->exactly(2))->method('backfill')->willReturnCallback(
			static fn (string $after, int $limit): array => ($after === '100')
				? ['last' => '600', 'read' => $limit]
				: ['last' => '650', 'read' => 50]
		);
		$this->jobList->expects($this->never())->method('add');

		$this->runJob($job);

		$this->assertSame('650', $this->settings[SearchTermsRequest::CURSOR]);
		$this->assertSame('1', $this->settings[SearchTermsRequest::READY]);
	}

	public function testARunThatRunsOutOfTimeKeepsItsPlaceAndComesBack(): void {
		$job = $this->job();
		$this->terms->expects($this->once())->method('backfill')->with('0')->willReturnCallback(
			function (string $after, int $limit): array {
				$this->now += SearchIndex::SECONDS_PER_RUN;

				return ['last' => '500', 'read' => $limit];
			}
		);
		$this->jobList->expects($this->once())->method('add')->with(SearchIndex::class);

		$this->runJob($job);

		$this->assertSame('500', $this->settings[SearchTermsRequest::CURSOR]);
		$this->assertArrayNotHasKey(SearchTermsRequest::READY, $this->settings);
	}

	public function testAFailedRunKeepsTheLastFinishedChunkAndComesBack(): void {
		$job = $this->job();
		$this->terms->method('backfill')->willReturnCallback(static function (string $after, int $limit): array {
			if ($after === '0') {
				return ['last' => '500', 'read' => $limit];
			}

			throw new RuntimeException('the database went away');
		});
		$this->jobList->expects($this->once())->method('add')->with(SearchIndex::class);

		$this->runJob($job);

		$this->assertSame('500', $this->settings[SearchTermsRequest::CURSOR]);
		$this->assertArrayNotHasKey(SearchTermsRequest::READY, $this->settings);
		$this->assertSame('500:1', $this->settings[SearchTermsRequest::FAILING], 'the failure is counted at its cursor');
	}

	/**
	 * A failure at the same cursor is counted on; a run that gets past the
	 * cursor leaves the count behind with it, so a chunk that fails once in
	 * a while is not what shrinks the chunk.
	 */
	public function testFailuresAreCountedPerCursor(): void {
		$this->settings[SearchTermsRequest::CURSOR] = '500';
		$this->settings[SearchTermsRequest::FAILING] = '500:1';
		$job = $this->job();
		$this->terms->method('backfill')->willThrowException(new RuntimeException('still down'));

		$this->runJob($job);
		$this->assertSame('500:2', $this->settings[SearchTermsRequest::FAILING]);

		$this->settings[SearchTermsRequest::FAILING] = '400:1';
		$job = $this->job();
		$this->terms->method('backfill')->willThrowException(new RuntimeException('down again'));

		$this->runJob($job);
		$this->assertSame('500:1', $this->settings[SearchTermsRequest::FAILING], 'a count belongs to one cursor');
	}

	public function testAChunkThatHasFailedTwiceIsIndexedOnePostAtATime(): void {
		$this->settings[SearchTermsRequest::CURSOR] = '500';
		$this->settings[SearchTermsRequest::FAILING] = '500:' . SearchIndex::FAILURES_BEFORE_ISOLATING;
		$job = $this->job();
		$this->terms->expects($this->atLeastOnce())->method('backfill')->with($this->anything(), 1)
			->willReturnCallback(function (string $after, int $limit): array {
				$this->now += SearchIndex::SECONDS_PER_RUN;

				return ['last' => (string)((int)$after + 1), 'read' => 1];
			});

		$this->runJob($job);

		$this->assertSame('501', $this->settings[SearchTermsRequest::CURSOR]);
	}

	/**
	 * The one failure that can only be one post: with the chunk down to a
	 * single post, the post after the cursor is the one that cannot be
	 * indexed. It is named, counted and passed over, so the run after this
	 * one starts beyond it — the index is never held for ever at one post.
	 */
	public function testAChunkOfOneThatFailsNamesThePostAndPassesOverIt(): void {
		$this->settings[SearchTermsRequest::CURSOR] = '500';
		$this->settings[SearchTermsRequest::FAILING] = '500:' . SearchIndex::FAILURES_BEFORE_ISOLATING;
		$this->settings[SearchTermsRequest::SKIPPED] = '2';
		$job = $this->job();
		$this->terms->method('backfill')->with('500', 1)->willThrowException(new RuntimeException('cannot be tokenized'));
		$this->terms->method('nextNid')->with('500')->willReturn('517');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('skipping post 517'), $this->arrayHasKey('exception'));
		$this->jobList->expects($this->once())->method('add')->with(SearchIndex::class);

		$this->runJob($job);

		$this->assertSame('517', $this->settings[SearchTermsRequest::CURSOR], 'the next run starts past the post');
		$this->assertSame('3', $this->settings[SearchTermsRequest::SKIPPED]);
		$this->assertArrayNotHasKey(SearchTermsRequest::FAILING, $this->settings);
		$this->assertArrayNotHasKey(SearchTermsRequest::READY, $this->settings);
	}

	public function testEveryFailedRunChangesWhatTheNextOneDoes(): void {
		// a chunk that fails for good: whole, whole again, then one post at a
		// time, and that post skipped — four runs and the cursor has moved
		$this->settings[SearchTermsRequest::CURSOR] = '500';
		$limits = [];
		for ($run = 0; $run < SearchIndex::FAILURES_BEFORE_ISOLATING + 1; $run++) {
			$job = $this->job();
			$this->terms->method('backfill')->willReturnCallback(static function (string $after, int $limit) use (&$limits): array {
				$limits[] = $limit;

				throw new RuntimeException('a row that cannot be indexed');
			});
			$this->terms->method('nextNid')->willReturn('501');
			$this->runJob($job);
		}

		$this->assertSame([SearchIndex::CHUNK, SearchIndex::CHUNK, 1], $limits);
		$this->assertSame('501', $this->settings[SearchTermsRequest::CURSOR]);
	}

	public function testOnceTheIndexIsCompleteItDoesNothing(): void {
		$job = $this->job(true);
		$this->terms->expects($this->never())->method('backfill');
		$this->jobList->expects($this->never())->method('add');

		$this->runJob($job);
	}
}
