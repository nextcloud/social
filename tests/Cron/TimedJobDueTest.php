<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCA\Social\Cron\Cache;
use OCA\Social\Cron\Queue;
use OCA\Social\Tests\Helper\FrozenClock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

/**
 * Every background job of the app, asked on a frozen clock whether it is due.
 *
 * A timed job is due once more than its interval has passed since it last
 * ran: on the second its interval ends it is still skipped, on the next it
 * runs. That rule is Nextcloud's own `TimedJob::start()`; what is pinned here
 * is that each job hands it the clock it was built with, and the intervals
 * the two 12-minute jobs, the delivery queue and the cache maintenance,
 * are built with.
 *
 * Whether a job ran is read off the job list: `Job::start()` records the run
 * before it does anything else, and the list here refuses to, so no job's
 * work is done.
 */
#[AllowMockObjectsWithoutExpectations]
class TimedJobDueTest extends TestCase {
	private const LAST_RUN = 1772323200;

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	/** @return array<string, array{class-string<TimedJob>}> */
	public static function timedJobs(): array {
		$jobs = [];
		foreach (glob(__DIR__ . '/../../lib/Cron/*.php') ?: [] as $file) {
			$class = 'OCA\\Social\\Cron\\' . basename($file, '.php');
			if (is_subclass_of($class, TimedJob::class)) {
				$jobs[basename($file, '.php')] = [$class];
			}
		}

		return $jobs;
	}

	public function testEveryTimedJobOfTheAppIsAsked(): void {
		$this->assertGreaterThanOrEqual(18, count(self::timedJobs()));
		$this->assertArrayHasKey('Cache', self::timedJobs());
		$this->assertArrayHasKey('Queue', self::timedJobs());
	}

	/** @param class-string<TimedJob> $class */
	#[DataProvider('timedJobs')]
	public function testAJobIsSkippedOnTheSecondItsIntervalEndsAndRunsOnTheNext(string $class): void {
		$clock = new FrozenClock(self::LAST_RUN);
		$job = $this->build($class, $clock);
		$job->setLastRun(self::LAST_RUN);
		$interval = $job->getInterval();
		$this->assertGreaterThan(0, $interval, $class . ' has an interval');

		$clock->set(self::LAST_RUN + $interval);
		$this->assertFalse($this->ran($job), 'ran on the second its interval ends');

		$clock->advance(1);
		$this->assertTrue($this->ran($job), 'did not run a second later');
	}

	/** @return array<string, array{class-string<TimedJob>, int}> */
	public static function twelveMinuteJobs(): array {
		return [
			'the delivery queue' => [Queue::class, 12 * 60],
			'the cache maintenance' => [Cache::class, 12 * 60],
		];
	}

	/** @param class-string<TimedJob> $class */
	#[DataProvider('twelveMinuteJobs')]
	public function testTheTwelveMinuteJobsAreDueEveryTwelveMinutes(string $class, int $interval): void {
		$clock = new FrozenClock(self::LAST_RUN);
		$job = $this->build($class, $clock);

		$this->assertSame($interval, $job->getInterval());
		foreach ([1, 2, 5] as $runs) {
			$job->setLastRun(self::LAST_RUN + ($runs - 1) * ($interval + 1));
			$clock->set(self::LAST_RUN + $runs * ($interval + 1) - 1);
			$this->assertFalse($this->ran($job), 'run ' . $runs . ' started a second early');
			$clock->advance(1);
			$this->assertTrue($this->ran($job), 'run ' . $runs . ' did not start on time');
		}
	}

	/**
	 * Whether `start()` went ahead: the job list it is handed refuses the run
	 * at the first thing `Job::start()` does, recording it.
	 */
	private function ran(TimedJob $job): bool {
		$jobList = $this->createStub(IJobList::class);
		$jobList->method('setLastRun')->willThrowException(new RuntimeException('started'));

		try {
			$job->start($jobList);
		} catch (RuntimeException $e) {
			return $e->getMessage() === 'started';
		}

		return false;
	}

	/**
	 * The job as the container builds it, with the clock given and a stand-in
	 * for everything else it takes.
	 *
	 * @param class-string<TimedJob> $class
	 */
	private function build(string $class, ITimeFactory $clock): TimedJob {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			$name = ($type instanceof ReflectionNamedType) ? $type->getName() : '';
			$arguments[] = match (true) {
				$name === ITimeFactory::class => $clock,
				$parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
				$type instanceof ReflectionNamedType && !$type->isBuiltin() => $this->createStub($name),
				default => $this->fail('cannot build ' . $class . ': $' . $parameter->getName()),
			};
		}

		$job = new $class(...$arguments);
		$this->assertInstanceOf(TimedJob::class, $job);
		$time = new ReflectionProperty($job, 'time');
		$this->assertSame($clock, $time->getValue($job), $class . ' keeps the clock it was given');

		return $job;
	}
}
